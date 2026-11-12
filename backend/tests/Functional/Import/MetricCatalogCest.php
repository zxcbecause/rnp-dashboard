<?php

declare(strict_types=1);

namespace App\Tests\Functional\Import;

use App\Entity\Metric\Metric;
use App\Entity\Metric\MetricValue;
use App\Entity\Org\OrgMembership;
use App\Enum\MetricSourceType;
use App\Enum\MetricUnit;
use App\Enum\OrgRole;
use App\Enum\PeriodType;
use App\Enum\TimeAggregation;
use App\Enum\ValueOrigin;
use App\Service\Metric\MetricCatalogLoader;
use App\Service\Org\OrgTreeManager;
use App\Tests\Support\FunctionalTester;
use BcMath\Number;

final class MetricCatalogCest
{
    private string $file = '';

    public function _before(FunctionalTester $I): void
    {
        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $company = $tree->createUnit('Company');
        $sales = $tree->createUnit('Sales', $company);
        $head = $I->haveUser('head@catalog.local', 'Head');
        $sales->setHeadUser($head);
        $em = $I->grabEntityManager();
        $em->persist(new OrgMembership($head, $sales, OrgRole::Head));
        $em->flush();

        $this->file = tempnam(sys_get_temp_dir(), 'catalog').'.yaml';
    }

    public function _after(FunctionalTester $I): void
    {
        @unlink($this->file);
    }

    public function catalogCreatesThenKeepsAndUpdatesMetricsByCode(FunctionalTester $I): void
    {
        $this->write(<<<'YAML'
            units:
              Sales:
                sort_from: 400
                metrics:
                  - code: t_calls
                    name: Звонки
                    card: ['Исходящие звонки', 'Σ звонков', 'Вручную', 'Неделя']
                  - code: t_score
                    name: eNPS
                    unit: score
                    time_agg: last
                    granularity: month
                  - code: t_ratio
                    name: Звонков на встречу
                    unit: ratio
                    time_agg: formula
                    formula: t_calls / t_score
            YAML);
        /** @var MetricCatalogLoader $loader */
        $loader = $I->grabService(MetricCatalogLoader::class);

        $I->assertSame(['created', 'created', 'created'], array_column($loader->load($this->file, dryRun: true), 'action'));
        $I->seeNumRecords(0, Metric::class, ['code' => 't_calls']);

        $I->assertSame(['created', 'created', 'created'], array_column($loader->load($this->file), 'action'));
        $calls = $I->grabEntityFromRepository(Metric::class, ['code' => 't_calls']);
        $I->assertSame(['Звонки', MetricUnit::Count, 410, 'Head', 'Head', 'Исходящие звонки'], [
            $calls->getName(), $calls->getUnit(), $calls->getSort(), $calls->getOwnerUser()?->getName(), $calls->getFillerUser()?->getName(), $calls->getWhatMeasured(),
        ]);
        $ratio = $I->grabEntityFromRepository(Metric::class, ['code' => 't_ratio']);
        $I->assertSame([TimeAggregation::Formula, null], [$ratio->getTimeAgg(), $ratio->getFillerUser()], 'formulas have no filler');

        // Re-running changes nothing; an edited name is an update of the same metric.
        $I->assertSame(['unchanged', 'unchanged', 'unchanged'], array_column($loader->load($this->file), 'action'));
        file_put_contents($this->file, str_replace('name: Звонки', 'name: Исходящие звонки', (string) file_get_contents($this->file)));
        $I->assertSame(['updated', 'unchanged', 'unchanged'], array_column($loader->load($this->file), 'action'));
        $I->seeNumRecords(1, Metric::class, ['code' => 't_calls', 'name' => 'Исходящие звонки']);

        // A plan formula is part of the definition.
        file_put_contents($this->file, str_replace("    name: eNPS\n", "    name: eNPS\n        plan_formula: t_ratio.plan * t_calls\n", (string) file_get_contents($this->file)));
        $I->assertSame(['unchanged', 'updated', 'unchanged'], array_column($loader->load($this->file), 'action'));
        $I->seeNumRecords(1, Metric::class, ['code' => 't_score', 'planFormula' => 't_ratio.plan * t_calls']);

        // A source turns the metric into a computed one; nobody fills it then. A fact typed in while it was
        // manual is released to the recalculation (it could no longer be edited); other metrics keep theirs.
        $em = $I->grabEntityManager();
        $em->persist(new MetricValue($calls, PeriodType::Week, new \DateTimeImmutable('2026-11-02'), new Number('7'), ValueOrigin::Manual));
        $score = $I->grabEntityFromRepository(Metric::class, ['code' => 't_score']);
        $em->persist(new MetricValue($score, PeriodType::Month, new \DateTimeImmutable('2026-11-01'), new Number('20'), ValueOrigin::Manual));
        $em->flush();
        file_put_contents($this->file, str_replace("    name: Исходящие звонки\n", "    name: Исходящие звонки\n        source: platform_api\n        source_config: {calculator: bookings_count, date_field: departure_date}\n", (string) file_get_contents($this->file)));
        $I->assertSame(['updated', 'unchanged', 'unchanged'], array_column($loader->load($this->file), 'action'));
        $calls = $I->grabEntityFromRepository(Metric::class, ['code' => 't_calls']);
        $I->assertSame([MetricSourceType::PlatformApi, ['calculator' => 'bookings_count', 'date_field' => 'departure_date'], null], [$calls->getSourceType(), $calls->getSourceConfig(), $calls->getFillerUser()]);
        $I->seeNumRecords(1, MetricValue::class, ['metric' => $calls, 'origin' => ValueOrigin::Import]);
        $I->seeNumRecords(1, MetricValue::class, ['metric' => $score, 'origin' => ValueOrigin::Manual]);
        $I->assertSame(['unchanged', 'unchanged', 'unchanged'], array_column($loader->load($this->file), 'action'));
    }

    public function invalidCatalogsAreRejectedBeforeWriting(FunctionalTester $I): void
    {
        /** @var MetricCatalogLoader $loader */
        $loader = $I->grabService(MetricCatalogLoader::class);

        foreach ([
            "units:\n  Nowhere:\n    metrics:\n      - {code: t_x, name: X}\n" => 'Org unit "Nowhere" not found',
            "units:\n  Sales:\n    metrics:\n      - {code: t_ok, name: Ok}\n      - {code: 'Bad Code', name: X}\n" => 'code (a-z',
            "units:\n  Sales:\n    metrics:\n      - {code: t_f, name: F, time_agg: formula}\n" => 'go together',
            "units:\n  Sales:\n    metrics:\n      - {code: t_p, name: P, plan_formula: 't_x.fact * 2'}\n" => 't_p: Invalid formula',
            "units:\n  Sales:\n    metrics:\n      - {code: t_s, name: S, source: platform_api}\n" => 't_s: source_config goes with',
            "units:\n  Sales:\n    metrics:\n      - {code: t_s, name: S, source_config: {calculator: calls}}\n" => 't_s: source_config goes with',
            "units:\n  Sales:\n    metrics:\n      - {code: t_s, name: S, source: bitrix, source_config: {calculator: nope}}\n" => 't_s: Unknown calculator',
            "units:\n  Sales:\n    metrics:\n      - {code: t_s, name: S, source: excel}\n" => 't_s: unknown source',
            "units:\n  Sales:\n    metrics:\n      - {code: t_s, name: S, time_agg: formula, formula: a / b, source: platform_api, source_config: {calculator: bookings_count}}\n" => 't_s: a formula takes no source_config',
        ] as $yaml => $message) {
            $this->write($yaml);
            try {
                $loader->load($this->file);
                $I->fail('Expected the catalog to be rejected: '.$message);
            } catch (\InvalidArgumentException $e) {
                $I->assertStringContainsString($message, $e->getMessage());
            }
        }
        $I->seeNumRecords(0, Metric::class, ['code' => 't_ok']);
    }

    private function write(string $yaml): void
    {
        file_put_contents($this->file, $yaml);
    }
}
