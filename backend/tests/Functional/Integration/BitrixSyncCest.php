<?php

declare(strict_types=1);

namespace App\Tests\Functional\Integration;

use App\Entity\Metric\Metric;
use App\Enum\MetricSourceType;
use App\Enum\MetricUnit;
use App\Enum\PeriodType;
use App\Enum\SyncStatus;
use App\Enum\TimeAggregation;
use App\Integration\Bitrix\Sync\BitrixSyncHandler;
use App\Integration\Bitrix\Sync\DealSyncer;
use App\Service\Metric\AutoMetricRecalculator;
use App\Service\Org\OrgTreeManager;
use App\Tests\Support\Bitrix\FakeBitrixApi;
use App\Tests\Support\FunctionalTester;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Dataset (Bitrix answers in +03:00; stored and counted in Madrid time; "today" = 2026-11-10;
 * segments: 26–31.10, 01.11, 02–08.11, 09–10.11):
 *
 *  D1 pipeline 4, created 02.11, no source: NEW → Inspección 04.11 → Contrato firmado 09.11 → back to Inspección 09.11
 *  D2 pipeline 20, created 01.11 01:30 +03 = 31.10 23:30 Madrid, "Marketing lead": No localizable 05.11 → Inspección 10.11
 *  D3 pipeline 20, created 03.11, "Cold call" (excluded): Inspección 05.11
 *  D4 pipeline 0 (guest bookings): not an owner deal, never requested
 *  Calls: C1 incoming missed 02.11, C2 incoming answered 02.11, C3 outgoing 02.11,
 *         C4 incoming missed 01.11 00:30 +03 = 31.10 Madrid
 */
final class BitrixSyncCest
{
    private FakeBitrixApi $api;

    public function _before(FunctionalTester $I): void
    {
        $this->api = new FakeBitrixApi();
        $this->api->lists = [
            'crm.status.list' => [
                $this->status('DEAL_STAGE_4', 'C4:NEW', 10, null),
                $this->status('DEAL_STAGE_4', 'C4:PREPAYMENT_INVOICE', 40, null),
                $this->status('DEAL_STAGE_4', 'C4:UC_G88IW0', 80, null),
                $this->status('DEAL_STAGE_4', 'C4:5', 190, 'F'),
                $this->status('DEAL_STAGE_20', 'C20:3', 40, null),
                $this->status('DEAL_STAGE_20', 'C20:2', 140, null),
                $this->status('DEAL_STAGE', 'WON', 50, 'S'),
                $this->status('SOURCE', 'CALL', 10, null),
            ],
            'crm.deal.list' => [
                $this->deal(1, 4, '2026-11-02T12:00:00+03:00', null),
                $this->deal(2, 20, '2026-11-01T01:30:00+03:00', '101'),
                $this->deal(3, 20, '2026-11-03T12:00:00+03:00', '102'),
                $this->deal(4, 0, '2026-11-02T12:00:00+03:00', null),
            ],
            'crm.stagehistory.list' => [
                $this->history(11, 1, 4, 'C4:NEW', '2026-11-02T12:00:00+03:00', 1),
                $this->history(12, 1, 4, 'C4:PREPAYMENT_INVOICE', '2026-11-04T12:00:00+03:00'),
                $this->history(13, 1, 4, 'C4:UC_G88IW0', '2026-11-09T12:00:00+03:00'),
                $this->history(14, 1, 4, 'C4:PREPAYMENT_INVOICE', '2026-11-09T15:00:00+03:00'),
                $this->history(15, 2, 20, 'C20:2', '2026-11-05T12:00:00+03:00'),
                $this->history(16, 3, 20, 'C20:3', '2026-11-05T12:00:00+03:00'),
                $this->history(17, 2, 20, 'C20:3', '2026-11-10T12:00:00+03:00'),
            ],
            'crm.activity.list' => [
                $this->call(21, 1, '2026-11-02T10:00:00+03:00', true),
                $this->call(22, 1, '2026-11-02T11:00:00+03:00', false),
                $this->call(23, 2, '2026-11-02T12:00:00+03:00', false),
                $this->call(24, 1, '2026-11-01T00:30:00+03:00', true),
            ],
        ];
        $this->api->results['crm.deal.fields'] = [
            DealSyncer::OWNER_SOURCE_FIELD => ['type' => 'enumeration', 'items' => [['ID' => '101', 'VALUE' => 'Marketing lead'], ['ID' => '102', 'VALUE' => 'Cold call']]],
        ];

        /** @var MockHttpClient $http */
        $http = $I->grabService('test.bitrix_http_client');
        $http->setResponseFactory($this->api);
    }

    public function syncStoresOwnerPipelinesWithoutPersonalData(FunctionalTester $I): void
    {
        $runs = $this->handler($I)->handle();

        $I->assertSame(['deal_stages', 'deals', 'deal_stage_history', 'calls'], array_map(static fn ($r): ?string => $r->getScope(), $runs));
        $I->assertSame([SyncStatus::Success], array_values(array_unique(array_map(static fn ($r): SyncStatus => $r->getStatus(), $runs), \SORT_REGULAR)));

        $db = $this->db($I);
        $I->assertSame(['C20:2', 'C20:3', 'C4:5', 'C4:NEW', 'C4:PREPAYMENT_INVOICE', 'C4:UC_G88IW0', 'WON'], $db->fetchFirstColumn('SELECT id FROM raw_bitrix_deal_stage ORDER BY id'));
        $I->assertSame(['category_id' => 0, 'semantics' => 'S'], $db->fetchAssociative("SELECT category_id, semantics FROM raw_bitrix_deal_stage WHERE id = 'WON'"));

        $I->assertSame([1, 2, 3], $db->fetchFirstColumn('SELECT id FROM raw_bitrix_deal ORDER BY id'), 'only pipelines 4 and 20');
        $I->assertSame(
            ['owner_source' => 'Marketing lead', 'date_create' => '2026-10-31 23:30:00'],
            $db->fetchAssociative('SELECT owner_source, date_create FROM raw_bitrix_deal WHERE id = 2'),
        );
        $I->assertSame(7, (int) $db->fetchOne('SELECT COUNT(*) FROM raw_bitrix_deal_stage_history'));

        $I->assertEquals([21 => 1, 22 => 0, 23 => 0, 24 => 1], $db->fetchAllKeyValue('SELECT id, missed FROM raw_bitrix_call ORDER BY id'));
        $I->assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM raw_bitrix_call WHERE payload LIKE '%+34%'"), 'phone numbers are not stored');
        $I->assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM raw_bitrix_deal WHERE payload LIKE '%Owner name%'"), 'deal titles are not stored');
    }

    public function longListsArePagedThroughBatches(FunctionalTester $I): void
    {
        $this->api->lists['crm.activity.list'] = [];
        for ($id = 1; $id <= 120; ++$id) {
            $this->api->lists['crm.activity.list'][] = $this->call($id, 1, '2026-10-20T10:00:00+03:00', false);
        }

        $this->handler($I)->handle(['calls']);

        $methods = array_column($this->api->requests, 'method');
        $I->assertSame(['crm.activity.list', 'batch'], $methods, 'first page alone, the other two pages in one batch');
        $I->assertSame([0, 50, 100], array_map(static fn (array $p): int => (int) $p['start'], $this->api->paramsOf('crm.activity.list')));
        $I->assertSame(120, (int) $this->db($I)->fetchOne('SELECT COUNT(*) FROM raw_bitrix_call'));
    }

    public function incrementalRunsAskOnlyForChanges(FunctionalTester $I): void
    {
        $this->handler($I)->handle();
        $this->api->requests = [];
        $this->handler($I)->handle();

        $deals = $this->api->paramsOf('crm.deal.list')[0]['filter'];
        $I->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $deals['>=DATE_MODIFY']);
        $I->assertSame([4, 20], $deals['@CATEGORY_ID']);
        $I->assertSame(17, $this->api->paramsOf('crm.stagehistory.list')[0]['filter']['>ID'], 'history is append-only');
        $I->assertArrayHasKey('>=LAST_UPDATED', $this->api->paramsOf('crm.activity.list')[0]['filter']);

        $this->api->requests = [];
        $this->handler($I)->handle(full: true);
        $I->assertArrayNotHasKey('>ID', $this->api->paramsOf('crm.stagehistory.list')[0]['filter'], '--full re-reads everything');
    }

    public function rateLimitIsRetriedAndErrorsDoNotLeakTheWebhook(FunctionalTester $I): void
    {
        $this->api->failures['crm.status.list'] = [[503, 'QUERY_LIMIT_EXCEEDED'], [503, 'QUERY_LIMIT_EXCEEDED']];
        $this->api->failures['crm.deal.fields'] = [[401, 'NO_AUTH_FOUND']];

        [$stages, $deals] = $this->handler($I)->handle(['deal_stages', 'deals']);

        $I->assertSame(SyncStatus::Success, $stages->getStatus(), 'QUERY_LIMIT_EXCEEDED is retried');
        $I->assertSame(SyncStatus::Failed, $deals->getStatus(), 'an auth error fails at once');
        $I->assertStringContainsString('NO_AUTH_FOUND', (string) $deals->getError());
        $I->assertStringNotContainsString('test-token', (string) $deals->getError());
    }

    public function metricsAreComputedFromDealsStageHistoryAndCalls(FunctionalTester $I): void
    {
        $metrics = $this->metrics($I);
        $this->handler($I)->handle();

        $result = $this->recalculator($I)->recalculate(new \DateTimeImmutable('2026-10-26'), new \DateTimeImmutable('2026-11-10'));
        $I->assertSame([], $result->errors);

        $segments = ['2026-10-26', '2026-11-01', '2026-11-02', '2026-11-09'];
        // D2 counts on 31.10 Madrid time; D3 is a cold call; D4 is not an owner deal.
        $I->assertSame(['2026-10-26' => '1', '2026-11-01' => '0', '2026-11-02' => '1', '2026-11-09' => '0'], $this->facts($I, $metrics['leads'], $segments));
        // D1 on its first move into Inspección (04.11), not again on 09.11; D2 only after "No localizable".
        $I->assertSame(['2026-10-26' => '0', '2026-11-01' => '0', '2026-11-02' => '1', '2026-11-09' => '1'], $this->facts($I, $metrics['qualified'], $segments));
        $I->assertSame(['2026-10-26' => '0', '2026-11-01' => '0', '2026-11-02' => '0', '2026-11-09' => '1'], $this->facts($I, $metrics['contracts'], $segments));
        $I->assertSame(['2026-10-26' => '1', '2026-11-01' => '0', '2026-11-02' => '2', '2026-11-09' => '0'], $this->facts($I, $metrics['calls'], $segments));
        $I->assertSame(['2026-10-26' => '1', '2026-11-01' => '0', '2026-11-02' => '1', '2026-11-09' => '0'], $this->facts($I, $metrics['missed'], $segments));
    }

    public function cohortCountsDealsOfTheMonthThatReachedTheStageLater(FunctionalTester $I): void
    {
        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $em = $I->grabEntityManager();
        $metric = new Metric($tree->createUnit('Marketing'), 't_new_objects', 'New objects', MetricUnit::Count);
        $metric->setSourceType(MetricSourceType::Bitrix)->setTimeAgg(TimeAggregation::MonthlyCohort)->setGranularity(PeriodType::Month)
            ->setSourceConfig(['calculator' => 'deals_cohort_reached_stage', 'categories' => [4, 20], 'stages' => ['C4:UC_G88IW0', 'C20:3'], 'exclude_owner_sources' => ['Cold call']]);
        $em->persist($metric);
        $em->flush();
        $this->handler($I)->handle();

        // Even a run asked for November only recounts the older cohorts (a year back): October grew in November.
        $this->recalculator($I)->recalculate(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-10'));

        $rows = $this->db($I)->fetchAllKeyValue('SELECT period_start, value FROM metric_value WHERE metric_id = ? AND period_type = ? ORDER BY period_start', [$metric->getId(), PeriodType::Month->value]);
        $I->assertCount(13, $rows, '11.2025 … 11.2026');
        // October cohort: D2 (created 31.10 Madrid) reached C20:3 on 10.11 — counted in its creation month.
        // November cohort: D1 reached the contract on 09.11; D3 is a cold call.
        $I->assertSame(['2026-10-01' => 1, '2026-11-01' => 1], array_filter(array_map(static fn ($v): int => (int) $v, $rows)));
        $I->assertSame(0, (int) $this->db($I)->fetchOne('SELECT COUNT(*) FROM metric_value WHERE metric_id = ? AND period_type = ?', [$metric->getId(), PeriodType::Week->value]));
    }

    public function fullSyncDropsRowsThatVanishedFromTheSource(FunctionalTester $I): void
    {
        $this->handler($I)->handle();
        $db = $this->db($I);
        // Make the stored rows look older than the next run (sync times have one-second precision).
        $db->executeStatement('UPDATE raw_bitrix_deal SET synced_at = synced_at - INTERVAL 1 HOUR');
        $db->executeStatement('UPDATE raw_bitrix_call SET synced_at = synced_at - INTERVAL 1 HOUR');

        // D3 was moved out of the owner pipelines; an incremental run cannot know.
        $this->api->lists['crm.deal.list'][2]['CATEGORY_ID'] = '0';
        $this->handler($I)->handle(['deals']);
        $I->assertSame([1, 2, 3], $db->fetchFirstColumn('SELECT id FROM raw_bitrix_deal ORDER BY id'));

        $this->handler($I)->handle(['deals'], full: true);
        $I->assertSame([1, 2], $db->fetchFirstColumn('SELECT id FROM raw_bitrix_deal ORDER BY id'));

        // A suspiciously small full pass (1 of 4 calls) deletes nothing.
        $this->api->lists['crm.activity.list'] = \array_slice($this->api->lists['crm.activity.list'], 0, 1);
        $this->handler($I)->handle(['calls'], full: true);
        $I->assertSame(4, (int) $db->fetchOne('SELECT COUNT(*) FROM raw_bitrix_call'));
    }

    public function commandSyncsAndRecalculates(FunctionalTester $I): void
    {
        $this->metrics($I);

        $output = $I->runSymfonyConsoleCommand('app:sync:bitrix', ['--recalc-from' => '2026-10-01']);

        $I->assertStringContainsString('deal_stage_history', $output);
        $I->assertStringContainsString('Metrics recalculated from 2026-10-01', $output);
        $I->assertSame(4, (int) $this->db($I)->fetchOne('SELECT COUNT(*) FROM raw_bitrix_call'));
    }

    /**
     * @return array<string, Metric>
     */
    private function metrics(FunctionalTester $I): array
    {
        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $unit = $tree->createUnit('Marketing');
        $em = $I->grabEntityManager();

        $notCold = ['exclude_owner_sources' => ['Cold call']];
        $metrics = [];
        foreach ([
            'leads' => ['calculator' => 'deals_created', 'categories' => [4, 20], ...$notCold],
            'qualified' => ['calculator' => 'deals_reached_stage', 'stages' => ['C4:PREPAYMENT_INVOICE', 'C4:UC_G88IW0', 'C20:3'], ...$notCold],
            'contracts' => ['calculator' => 'deals_reached_stage', 'stages' => ['C4:UC_G88IW0'], ...$notCold],
            'calls' => ['calculator' => 'calls', 'direction' => 'incoming'],
            'missed' => ['calculator' => 'calls', 'direction' => 'incoming', 'missed' => true],
        ] as $key => $config) {
            $metric = new Metric($unit, 't_'.$key, $key, MetricUnit::Count);
            $metric->setSourceType(MetricSourceType::Bitrix)->setSourceConfig($config);
            $em->persist($metric);
            $metrics[$key] = $metric;
        }
        $em->flush();

        return $metrics;
    }

    /**
     * @param list<string> $starts
     *
     * @return array<string, string> period start => integer value
     */
    private function facts(FunctionalTester $I, Metric $metric, array $starts): array
    {
        $rows = $this->db($I)->fetchAllKeyValue(
            'SELECT period_start, value FROM metric_value WHERE metric_id = ? AND period_type = ?',
            [$metric->getId(), PeriodType::Week->value],
        );

        $facts = [];
        foreach ($starts as $start) {
            $facts[$start] = isset($rows[$start]) ? (string) (int) $rows[$start] : 'missing';
        }

        return $facts;
    }

    /**
     * @return array<string, mixed>
     */
    private function status(string $entity, string $id, int $sort, ?string $semantics): array
    {
        return ['ID' => (string) crc32($entity.$id), 'ENTITY_ID' => $entity, 'STATUS_ID' => $id, 'NAME' => $id, 'SORT' => (string) $sort, 'SEMANTICS' => $semantics];
    }

    /**
     * @return array<string, mixed>
     */
    private function deal(int $id, int $category, string $created, ?string $source): array
    {
        return [
            'ID' => (string) $id, 'TITLE' => 'Owner name '.$id, 'CATEGORY_ID' => (string) $category, 'STAGE_ID' => 'C'.$category.':NEW',
            'STAGE_SEMANTIC_ID' => 'P', 'SOURCE_ID' => null, 'UTM_SOURCE' => null, 'ASSIGNED_BY_ID' => '7', 'LEAD_ID' => null,
            'CLOSED' => 'N', 'DATE_CREATE' => $created, 'DATE_MODIFY' => $created, 'CLOSEDATE' => null,
            DealSyncer::OWNER_SOURCE_FIELD => $source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function history(int $id, int $deal, int $category, string $stage, string $at, int $type = 2): array
    {
        return ['ID' => $id, 'TYPE_ID' => $type, 'OWNER_ID' => $deal, 'CREATED_TIME' => $at, 'CATEGORY_ID' => $category, 'STAGE_ID' => $stage, 'STAGE_SEMANTIC_ID' => 'P'];
    }

    /**
     * @return array<string, mixed>
     */
    private function call(int $id, int $direction, string $start, bool $missed): array
    {
        return [
            'ID' => (string) $id, 'TYPE_ID' => '2', 'SUBJECT' => 'Incoming from +34 600 000 '.$id, 'DIRECTION' => (string) $direction,
            'COMPLETED' => 'Y', 'START_TIME' => $start, 'END_TIME' => $start, 'OWNER_TYPE_ID' => '2', 'OWNER_ID' => '1',
            'RESPONSIBLE_ID' => '7', 'LAST_UPDATED' => $start, 'PROVIDER_ID' => 'VOXIMPLANT_CALL',
            'SETTINGS' => $missed ? ['IS_DESCRIPTION_ONLY' => true, 'MISSED_CALL' => true] : ['IS_DESCRIPTION_ONLY' => true],
        ];
    }

    private function handler(FunctionalTester $I): BitrixSyncHandler
    {
        /** @var BitrixSyncHandler $handler */
        $handler = $I->grabService(BitrixSyncHandler::class);

        return $handler;
    }

    private function recalculator(FunctionalTester $I): AutoMetricRecalculator
    {
        /** @var AutoMetricRecalculator $recalculator */
        $recalculator = $I->grabService(AutoMetricRecalculator::class);

        return $recalculator;
    }

    private function db(FunctionalTester $I): Connection
    {
        /** @var Connection $connection */
        $connection = $I->grabService('doctrine.dbal.default_connection');

        return $connection;
    }
}
