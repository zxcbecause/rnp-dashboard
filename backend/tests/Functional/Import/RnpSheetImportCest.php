<?php

declare(strict_types=1);

namespace App\Tests\Functional\Import;

use App\Entity\Auth\User;
use App\Entity\Metric\Metric;
use App\Entity\Metric\MetricValue;
use App\Enum\MetricSourceType;
use App\Enum\MetricUnit;
use App\Enum\PeriodType;
use App\Enum\TimeAggregation;
use App\Enum\ValueOrigin;
use App\Service\Import\RnpSheet\RnpSheetImportHandler;
use App\Service\Org\OrgTreeManager;
use App\Tests\Support\FunctionalTester;
use BcMath\Number;
use Doctrine\DBAL\Connection;

/**
 * A trimmed copy of the real October 2026 sheet layout: weeks 01–04.10, 05–11.10 and 26–31.10
 * (the last one headed just "ФАКТ"), formatted cells, section rows and sub-headers.
 */
final class RnpSheetImportCest
{
    private string $file;

    /** @var array<string, Metric> */
    private array $metrics = [];

    public function _before(FunctionalTester $I): void
    {
        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $unit = $tree->createUnit('Dept');
        $em = $I->grabEntityManager();

        foreach ([
            ['mkt_ad_spend', MetricUnit::Eur, MetricSourceType::Manual, TimeAggregation::Sum],
            ['mkt_leads', MetricUnit::Count, MetricSourceType::Bitrix, TimeAggregation::Sum],
            ['mkt_cpl', MetricUnit::Eur, MetricSourceType::Manual, TimeAggregation::Formula],
            ['rev_bookings_sum', MetricUnit::Eur, MetricSourceType::PlatformApi, TimeAggregation::Sum],
            ['rev_stayed_sum', MetricUnit::Eur, MetricSourceType::PlatformApi, TimeAggregation::Sum],
            ['rev_occupancy', MetricUnit::Percent, MetricSourceType::PlatformApi, TimeAggregation::Formula],
            ['cs_response_minutes', MetricUnit::Minutes, MetricSourceType::Manual, TimeAggregation::Avg],
            ['cs_missed_calls_share', MetricUnit::Percent, MetricSourceType::PlatformApi, TimeAggregation::Formula],
        ] as [$code, $unitType, $source, $agg]) {
            $metric = new Metric($unit, $code, $code, $unitType);
            $metric->setSourceType($source)->setTimeAgg($agg);
            $em->persist($metric);
            $this->metrics[$code] = $metric;
        }
        $em->flush();

        $this->file = (string) tempnam(sys_get_temp_dir(), 'rnp');
        $this->writeSheet($this->file);
    }

    public function _after(): void
    {
        @unlink($this->file);
    }

    public function importsMonthlyPlansOfMappedMetricsAndFactsOfManualOnes(FunctionalTester $I): void
    {
        $report = $this->handler($I)->import($this->file, 2026, 10, today: new \DateTimeImmutable('2026-11-04'));

        $I->assertSame([
            'mkt_ad_spend' => '3925', 'mkt_leads' => '75', 'mkt_cpl' => '41.5', 'rev_bookings_sum' => '156000', 'rev_stayed_sum' => '156000',
            'rev_occupancy' => '0.45', 'cs_response_minutes' => '45', 'cs_missed_calls_share' => '0.2',
        ], $this->plans($I));

        // Manual metrics only; the Bitrix metric "Лиды" keeps its computed facts.
        $I->assertSame([
            'mkt_ad_spend' => ['2026-10-01' => '48', '2026-10-05' => '93', '2026-10-26' => '484'],
            'cs_response_minutes' => ['2026-10-01' => '32', '2026-10-26' => '13'],
        ], $this->facts($I));
        $I->assertSame([ValueOrigin::Import->value], $this->db($I)->fetchFirstColumn('SELECT DISTINCT origin FROM metric_value'));

        $I->assertSame(['Marketing / Верх воронки: клики'], $report->unmapped);
        $I->assertCount(1, $report->invalid);
        $I->assertStringContainsString('тех пробл', $report->invalid[0]);
        $I->assertSame(8, $report->plansWritten);
        $I->assertSame(5, $report->factsWritten);
    }

    public function reimportIsIdempotentAndKeepsDashboardInput(FunctionalTester $I): void
    {
        $em = $I->grabEntityManager();
        $user = new User('marta@example.test', 'Marta');
        $em->persist($user);
        $em->persist(new MetricValue($this->metrics['mkt_ad_spend'], PeriodType::Week, new \DateTimeImmutable('2026-10-05'), new Number('100'), ValueOrigin::Manual, $user));
        $em->flush();

        $today = new \DateTimeImmutable('2026-11-04');
        $this->handler($I)->import($this->file, 2026, 10, today: $today);
        $again = $this->handler($I)->import($this->file, 2026, 10, today: $today);

        $I->assertSame(0, $again->plansWritten + $again->factsWritten);
        $I->assertSame(8, $again->plansUnchanged);
        $I->assertSame(1, $again->factsKeptManual);
        $I->assertSame('100', $this->facts($I)['mkt_ad_spend']['2026-10-05'], 'typed in the dashboard');

        $this->handler($I)->import($this->file, 2026, 10, overwriteManual: true, today: $today);
        $I->assertSame('93', $this->facts($I)['mkt_ad_spend']['2026-10-05']);
    }

    public function dryRunWritesNothingAndFutureWeeksAreSkipped(FunctionalTester $I): void
    {
        $dry = $this->handler($I)->import($this->file, 2026, 10, dryRun: true, today: new \DateTimeImmutable('2026-11-04'));
        $I->assertSame(5, $dry->factsWritten);
        $I->assertSame(0, (int) $this->db($I)->fetchOne('SELECT COUNT(*) FROM metric_value') + (int) $this->db($I)->fetchOne('SELECT COUNT(*) FROM metric_plan'));

        $this->handler($I)->import($this->file, 2026, 10, today: new \DateTimeImmutable('2026-10-20'));
        $I->assertSame(['2026-10-01' => '48', '2026-10-05' => '93'], $this->facts($I)['mkt_ad_spend'], 'week of 26.10 has not started yet');
    }

    public function wrongMonthIsRejected(FunctionalTester $I): void
    {
        $I->expectThrowable(\InvalidArgumentException::class, fn () => $this->handler($I)->import($this->file, 2026, 11));
    }

    public function commandPrintsTheReport(FunctionalTester $I): void
    {
        $output = $I->runSymfonyConsoleCommand('app:import:rnp-sheet', ['file' => $this->file, '--month' => '2026-10', '--dry-run' => true, '--show-unmapped' => true]);

        $I->assertStringContainsString('Dry run of 2026-10', $output);
        $I->assertStringContainsString('Верх воронки: клики', $output);
        $I->assertStringContainsString('тех пробл', $output);
    }

    private function writeSheet(string $path): void
    {
        $days = static fn (array $labels): array => $labels;
        $header = [
            '', '', '', '', '', "План\nмесяца", 'Факт месяца', "Run rate \nмесяца",
            ...$days(['чт 01.10', 'пт 02.10', 'сб 03.10', 'вс 04.10']), "ФАКТ \n01.10-04.10", "ПЛАН\n01.10-04.10", "% ВЫПОЛНЕНИЯ\n01.10-04.10",
            ...$days(['пн 05.10', 'вт 06.10', 'ср 07.10', 'чт 08.10', 'пт 09.10', 'сб 10.10', 'вс 11.10']), "ФАКТ НЕДЕЛИ\n05.10–11.10", 'ПЛАН НЕДЕЛИ 05.10–11.10', '% ВЫПОЛНЕНИЯ 05.10–11.10',
            ...$days(['пн 26.10', 'вт 27.10', 'ср 28.10', 'чт 29.10', 'пт 30.10', 'сб 31.10']), 'ФАКТ', 'ПЛАН', '% ВЫПОЛНЕНИЯ',
        ];
        // Row: name, plan, then [fact, plan] of each of the three weeks (day cells left empty).
        $row = static fn (string $n, string $name, string $plan, array $weeks): array => [
            $n, $name, 'Олег', 'Олег', 'Сумма', $plan, '', '',
            '', '', '', '', $weeks[0], '', '',
            '', '', '', '', '', '', '', $weeks[1], '', '',
            '', '', '', '', '', '', $weeks[2], '', '',
        ];

        $lines = [
            ['№', 'Название метрики (что в нее входит: количество, сумма)', 'Заполняющий', 'Ответственный', 'Как считается факт недели'],
            $header,
            ['', 'Marketing'],
            $row('1', 'Расход на рекламу', '€3 925', ['€48', '€93', '€484']),
            ['', 'Воронка собственников'],
            $row('', 'Верх воронки: клики', '', ['', '', '383']),
            $row('4', 'Лиды', '75', ['3', '8', '29']),
            $row('6', 'CPL', '€41,5', ['€40,9', '€27,9', '€20,5']),
            ['', 'Revenue'],
            $row('1', 'Сумма бронирований (прожили) - 5000', '€156 000', ['€13 293', '€34 676', '€26 160']),
            $row('2', 'Сумма бронирований (общая)  - 5000', '€156 000', ['€13 293', '€34 676', '€26 160']),
            $row('18', 'Загрузка по гостевым бронированиям - 20% (после 16 ноября - 15%)', '45%', ['28%', '37%', '49%']),
            ['', 'CS'],
            $row('4', 'Среднее время ответов на сообщения (мин) 15', '45', ['32', 'тех пробл', '13']),
            $row('5', 'Количество пропущенных звонков % (15%)', '20%', ['23%', '19%', '8%']),
        ];

        $handle = fopen($path, 'w');
        \assert(false !== $handle);
        fwrite($handle, "\u{FEFF}");
        foreach ($lines as $line) {
            fputcsv($handle, $line, escape: '');
        }
        fclose($handle);
    }

    /**
     * @return array<string, string>
     */
    private function plans(FunctionalTester $I): array
    {
        $rows = $this->db($I)->fetchAllKeyValue(
            "SELECT m.code, p.value FROM metric_plan p JOIN metric m ON m.id = p.metric_id WHERE p.period_type = 'month' AND p.period_start = '2026-10-01' ORDER BY m.id",
        );

        return array_map(fn ($v): string => $this->trim((string) $v), $rows);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function facts(FunctionalTester $I): array
    {
        $facts = [];
        foreach ($this->db($I)->fetchAllAssociative("SELECT m.code, v.period_start, v.value FROM metric_value v JOIN metric m ON m.id = v.metric_id WHERE v.period_type = 'week' ORDER BY m.id, v.period_start") as $r) {
            $facts[(string) $r['code']][(string) $r['period_start']] = $this->trim((string) $r['value']);
        }

        return $facts;
    }

    private function trim(string $value): string
    {
        $value = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;

        return '' === $value ? '0' : $value;
    }

    private function handler(FunctionalTester $I): RnpSheetImportHandler
    {
        /** @var RnpSheetImportHandler $handler */
        $handler = $I->grabService(RnpSheetImportHandler::class);

        return $handler;
    }

    private function db(FunctionalTester $I): Connection
    {
        /** @var Connection $connection */
        $connection = $I->grabService('doctrine.dbal.default_connection');

        return $connection;
    }
}
