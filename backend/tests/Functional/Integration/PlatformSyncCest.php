<?php

declare(strict_types=1);

namespace App\Tests\Functional\Integration;

use App\Entity\Metric\Metric;
use App\Entity\Metric\MetricValue;
use App\Enum\MetricSourceType;
use App\Enum\MetricUnit;
use App\Enum\PeriodType;
use App\Enum\SyncStatus;
use App\Enum\TimeAggregation;
use App\Enum\ValueOrigin;
use App\Integration\Platform\ExcludedAccommodations;
use App\Integration\Platform\Sync\PlatformSyncHandler;
use App\Service\Metric\AutoMetricRecalculator;
use App\Service\Metric\Calculation\PlatformMetricConfig;
use App\Service\Org\OrgTreeManager;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\Platform\FakePlatformApi;
use BcMath\Number;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Dataset (Madrid time, "today" = 2026-11-10; Nov 2026 segments: 01, 02–08, 09–15...):
 *
 *  B1 created 01.11 10:00, 100 EUR CONFIRMED, stay 05.11–08.11 (3 nights)
 *  B2 created 02.11, 250.50 EUR PAID, stay 07.11–10.11
 *  B3 created 03.11, 999 CANCELLED (modified 04.11), stay 06.11–07.11
 *  B4 created 31.10 23:30 UTC = 01.11 00:30 Madrid, 50 EUR CONFIRMED, stay 30.10–03.11
 *  B5 created 02.11, 70 USD CONFIRMED, no stay dates
 *  A1 ACTIVE on sale since 2026-01-01; A2 INACTIVE on sale 2026-01-01 → 2026-11-04; A3 DELETED, never on sale
 */
final class PlatformSyncCest
{
    private FakePlatformApi $api;

    public function _before(FunctionalTester $I): void
    {
        $this->api = new FakePlatformApi();
        $this->api->lists = [
            '/api/bookings' => [
                $this->booking(1, '2026-11-01T10:00:00+01:00', '100.00', 'CONFIRMED', '2026-11-05T00:00:00+01:00', '2026-11-08T00:00:00+01:00'),
                $this->booking(2, '2026-11-02T09:00:00+01:00', '250.50', 'PAID', '2026-11-07T00:00:00+01:00', '2026-11-10T00:00:00+01:00'),
                $this->booking(3, '2026-11-03T09:00:00+01:00', '999.00', 'CANCELLED', '2026-11-06T00:00:00+01:00', '2026-11-07T00:00:00+01:00', '2026-11-04T12:00:00+01:00'),
                $this->booking(4, '2026-10-31T23:30:00+00:00', '50.00', 'CONFIRMED', '2026-10-30T00:00:00+01:00', '2026-11-03T00:00:00+01:00'),
                $this->booking(5, '2026-11-02T11:00:00+01:00', '70.00', 'CONFIRMED', null, null, null, 'USD'),
            ],
            '/api/accommodations' => [
                $this->accommodation(1, 'ACTIVE', '2026-01-01T00:00:00+01:00', null),
                $this->accommodation(2, 'INACTIVE', '2026-01-01T00:00:00+01:00', '2026-11-04T00:00:00+01:00'),
            ],
            '/api/accommodations?status=DELETED' => [
                $this->accommodation(3, 'DELETED', null, null),
            ],
            '/api/housekeeping-tasks' => [
                ['id' => 11, 'status' => 'completed', 'type' => 'checkout_cleaning', 'accommodation' => ['id' => 1, 'name' => 'A1', 'details' => null], 'bookingId' => 1, 'assigneeId' => 7, 'assigneeName' => 'Ana', 'dueDate' => '2026-11-08T11:00:00+01:00', 'creationDate' => '2026-11-01T10:00:00+01:00'],
                ['id' => 12, 'status' => 'unassigned', 'type' => 'check-in_check-out', 'accommodation' => ['id' => 2, 'name' => 'A2', 'details' => null], 'bookingId' => null, 'assigneeId' => null, 'assigneeName' => null, 'dueDate' => null, 'creationDate' => '2026-11-02T10:00:00+01:00'],
            ],
            '/api/tickets' => [
                ['id' => 21, 'title' => 'Leaking tap', 'status' => 'open', 'type' => 'incident', 'priority' => 'high', 'accommodationId' => 1, 'accommodationName' => 'A1', 'housekeepingTaskId' => null, 'createdBy' => 'x', 'creationDate' => '2026-11-03T10:00:00+01:00', 'updatedAt' => null, 'price' => '45.90', 'isShowToOwner' => true],
            ],
        ];

        /** @var MockHttpClient $http */
        $http = $I->grabService('test.platform_http_client');
        $http->setResponseFactory($this->api);
    }

    public function syncCopiesEveryResourceIntoRawTables(FunctionalTester $I): void
    {
        $runs = $this->handler($I)->handle();
        $byScope = array_combine(array_map(static fn ($r) => (string) $r->getScope(), $runs), $runs);
        ksort($byScope);

        $I->assertSame(
            ['accommodations' => 'success', 'bookings' => 'success', 'housekeeping_tasks' => 'success', 'tickets' => 'success'],
            array_map(static fn ($r) => $r->getStatus()->value, $byScope),
        );
        $I->assertSame(5, $byScope['bookings']->getRows());
        $I->assertSame(3, $byScope['accommodations']->getRows());

        $db = $this->db($I);
        $I->assertSame(5, (int) $db->fetchOne('SELECT COUNT(*) FROM raw_platform_booking'));
        $I->assertSame(3, (int) $db->fetchOne('SELECT COUNT(*) FROM raw_platform_accommodation'), 'deleted ones come from a second call');
        $I->assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM raw_platform_housekeeping_task'));
        $I->assertSame('45.90', $db->fetchOne('SELECT price FROM raw_platform_ticket WHERE id = 21'));
        $I->assertSame(1, (int) $db->fetchOne('SELECT accommodation_id FROM raw_platform_housekeeping_task WHERE id = 11'));

        // 23:30 UTC on Oct 31 is already Nov 1 in Madrid.
        $I->assertSame('2026-11-01 00:30:00', $db->fetchOne('SELECT creation_date FROM raw_platform_booking WHERE id = 4'));
        $I->assertSame('250.50', $db->fetchOne('SELECT total FROM raw_platform_booking WHERE id = 2'));
        $payload = json_decode((string) $db->fetchOne('SELECT payload FROM raw_platform_booking WHERE id = 2'), true);
        $I->assertSame('PAID', $payload['status']);
    }

    public function secondRunIsIncrementalAndIdempotent(FunctionalTester $I): void
    {
        $this->handler($I)->handle(['bookings']);
        $this->api->lists['/api/bookings'][0]['total'] = '120.00';
        $this->handler($I)->handle(['bookings']);

        $queries = $this->api->queriesFor('/api/bookings');
        $I->assertArrayNotHasKey('lastModification', $queries[0], 'first run is full');
        $I->assertArrayHasKey('lastModification', $queries[\count($queries) - 1], 'next run asks for changes only');

        $db = $this->db($I);
        $I->assertSame(5, (int) $db->fetchOne('SELECT COUNT(*) FROM raw_platform_booking'));
        $I->assertSame('120.00', $db->fetchOne('SELECT total FROM raw_platform_booking WHERE id = 1'));

        $this->handler($I)->handle(['bookings'], full: true);
        $last = $this->api->queriesFor('/api/bookings');
        $I->assertArrayNotHasKey('lastModification', $last[\count($last) - 1], '--full ignores the last run');
    }

    public function failingResourceIsRecordedAndOthersContinue(FunctionalTester $I): void
    {
        $this->api->failures['/api/tickets'] = 500;

        $runs = $this->handler($I)->handle();
        $byScope = array_combine(array_map(static fn ($r) => (string) $r->getScope(), $runs), $runs);

        $I->assertSame(SyncStatus::Failed, $byScope['tickets']->getStatus());
        $I->assertStringContainsString('HTTP 500', (string) $byScope['tickets']->getError());
        $I->assertSame(SyncStatus::Success, $byScope['bookings']->getStatus());
        $I->assertSame(SyncStatus::Success, $byScope['housekeeping_tasks']->getStatus());
    }

    public function unknownResourceIsRejected(FunctionalTester $I): void
    {
        $I->expectThrowable(\InvalidArgumentException::class, fn () => $this->handler($I)->handle(['payments']));
    }

    public function revenueMetricsAreComputedPerWeekSegment(FunctionalTester $I): void
    {
        $metrics = $this->revenueMetrics($I);
        $this->handler($I)->handle();

        $result = $this->recalculator($I)->recalculate(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-10'));
        $I->assertSame([], $result->errors);
        $I->assertSame(['rev_avg_booking' => 'no calculator in source_config'], $result->skipped);

        // Sum by creation date: B4 moved to Nov 1 by the timezone, B3 cancelled, B5 not EUR.
        $I->assertSame(['2026-10-26' => '0', '2026-11-01' => '150', '2026-11-02' => '250.5', '2026-11-09' => '0'], $this->facts($I, $metrics['sum'], ['2026-10-26', '2026-11-01', '2026-11-02', '2026-11-09']));
        $I->assertSame(['2026-10-26' => '1', '2026-11-02' => '2'], $this->facts($I, $metrics['arrivals'], ['2026-10-26', '2026-11-02']));
        // Nights split across segments: B4 30–31.10 | 01.11 | 02.11; B1 3 + B2 2 in 02–08.11; B2's 9.11 night in the current one.
        $I->assertSame(['2026-10-26' => '2', '2026-11-01' => '1', '2026-11-02' => '6', '2026-11-09' => '1'], $this->facts($I, $metrics['nights'], ['2026-10-26', '2026-11-01', '2026-11-02', '2026-11-09']));
        // A1 every day; A2 until 03.11 inclusive; A3 never; current segment only up to today (09–10.11).
        $I->assertSame(['2026-11-01' => '2', '2026-11-02' => '9', '2026-11-09' => '2'], $this->facts($I, $metrics['available'], ['2026-11-01', '2026-11-02', '2026-11-09']));
        $I->assertSame(['2026-11-02' => '1'], $this->facts($I, $metrics['cancelled'], ['2026-11-02']));
        // Whole stays by check-in: B4 4 nights in the segment of 30.10; B1 3 + B2 3 in 02–08.11 (B2 ends on 10.11).
        $I->assertSame(['2026-10-26' => '4', '2026-11-01' => '0', '2026-11-02' => '6'], $this->facts($I, $metrics['checkin_nights'], ['2026-10-26', '2026-11-01', '2026-11-02']));
        // Every booking is in accommodation 1, an apartment: nothing for villas.
        $I->assertSame(['2026-11-02' => '0'], $this->facts($I, $metrics['villa_checkin_sum'], ['2026-11-02']));
        $I->assertSame(['2026-11-02' => '350.5'], $this->facts($I, $metrics['apt_checkin_sum'], ['2026-11-02']));
        // Stayed revenue, each booking spread over its nights: B4 50 / 4 nights (30.10–02.11), B1 100 / 3 (05–07.11),
        // B2 250.50 / 3 (07–09.11); 02–08.11 = B4 12.50 + B1 100 + B2 2 × 83.50; B3 cancelled, B5 not EUR.
        $I->assertSame(['2026-10-26' => '25', '2026-11-01' => '12.5', '2026-11-02' => '279.5', '2026-11-09' => '83.5'], $this->facts($I, $metrics['stayed'], ['2026-10-26', '2026-11-01', '2026-11-02', '2026-11-09']));

        // October has 5 segments, November up to the 10th has 3: no future periods are written.
        $I->assertSame(8, (int) $this->db($I)->fetchOne('SELECT COUNT(*) FROM metric_value WHERE metric_id = ?', [$metrics['sum']->getId()]));
        $I->assertSame(ValueOrigin::Auto->value, $this->db($I)->fetchOne('SELECT DISTINCT origin FROM metric_value WHERE metric_id = ?', [$metrics['sum']->getId()]));
    }

    public function stayCancelledAfterArrivalKeepsTheNightsBeforeTheCancellation(FunctionalTester $I): void
    {
        $metrics = $this->revenueMetrics($I);
        // B6: 500 for 5 nights 03–07.11, cancelled on 05.11 → the nights of 03.11 and 04.11 were stayed.
        $cancelled = $this->booking(6, '2026-11-01T10:00:00+01:00', '500.00', 'CANCELLED', '2026-11-03T00:00:00+01:00', '2026-11-08T00:00:00+01:00', '2026-11-05T09:00:00+01:00');
        $this->api->lists['/api/bookings'][] = $cancelled;
        // B7: 300 for 03–05.11, marked cancelled on 07.11 after departure (a no-show) → not stayed.
        $this->api->lists['/api/bookings'][] = $this->booking(7, '2026-11-01T10:00:00+01:00', '300.00', 'CANCELLED', '2026-11-03T00:00:00+01:00', '2026-11-05T00:00:00+01:00', '2026-11-07T09:00:00+01:00');
        $this->handler($I)->handle();
        $I->assertSame('2026-11-05 09:00:00', $this->db($I)->fetchOne('SELECT cancelled_at FROM raw_platform_booking WHERE id = 6'));
        $I->assertNull($this->db($I)->fetchOne('SELECT cancelled_at FROM raw_platform_booking WHERE id = 1'));

        $this->recalculator($I)->recalculate(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-10'));
        // 02–08.11: 279.50 as before + 2 × 100; B3 (cancelled before arrival) and B7 (after departure) count nothing.
        $I->assertSame(['2026-11-02' => '479.5'], $this->facts($I, $metrics['stayed'], ['2026-11-02']));
        $I->assertSame(['2026-11-02' => '6'], $this->facts($I, $metrics['nights'], ['2026-11-02']), 'sold nights still ignore cancelled bookings');

        // A later edit of the cancelled booking does not move the cancellation; reactivating it clears it.
        $this->api->lists['/api/bookings'][5]['lastModification'] = '2026-11-09T10:00:00+01:00';
        $this->handler($I)->handle(full: true);
        $I->assertSame('2026-11-05 09:00:00', $this->db($I)->fetchOne('SELECT cancelled_at FROM raw_platform_booking WHERE id = 6'));
        $this->api->lists['/api/bookings'][5]['status'] = 'CONFIRMED';
        $this->handler($I)->handle(full: true);
        $I->assertNull($this->db($I)->fetchOne('SELECT cancelled_at FROM raw_platform_booking WHERE id = 6'));
    }

    public function recalculationKeepsManualValuesAndIsIdempotent(FunctionalTester $I): void
    {
        $metrics = $this->revenueMetrics($I);
        $em = $I->grabEntityManager();
        $em->persist(new MetricValue($metrics['sum'], PeriodType::Week, new \DateTimeImmutable('2026-11-02'), new Number('7'), ValueOrigin::Manual));
        $em->persist(new MetricValue($metrics['sum'], PeriodType::Week, new \DateTimeImmutable('2026-11-01'), new Number('1'), ValueOrigin::Import));
        $em->flush();
        $this->handler($I)->handle();

        $first = $this->recalculator($I)->recalculate(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-10'));
        $second = $this->recalculator($I)->recalculate(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-10'));

        $I->assertSame(['2026-11-01' => '150', '2026-11-02' => '7'], $this->facts($I, $metrics['sum'], ['2026-11-01', '2026-11-02']));
        $I->assertSame(1, $first->keptManual);
        $I->assertSame(1, $second->keptManual);
        $I->assertSame(0, $second->written);
        $I->assertSame($first->written + $first->unchanged, $second->unchanged);
    }

    public function recalculationWithoutSyncedDataKeepsExistingFacts(FunctionalTester $I): void
    {
        $metrics = $this->revenueMetrics($I);
        $em = $I->grabEntityManager();
        $em->persist(new MetricValue($metrics['sum'], PeriodType::Week, new \DateTimeImmutable('2026-11-02'), new Number('42000'), ValueOrigin::Import));
        $em->flush();

        $result = $this->recalculator($I)->recalculate(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-10'));

        $I->assertSame('source not synced yet', $result->skipped['t_bookings_sum']);
        $I->assertSame(0, $result->written);
        $I->assertSame(['2026-11-02' => '42000'], $this->facts($I, $metrics['sum'], ['2026-11-02']));
    }

    public function commandSyncsAndRecalculates(FunctionalTester $I): void
    {
        $this->revenueMetrics($I);

        $output = $I->runSymfonyConsoleCommand('app:sync:platform', ['--recalc-from' => '2026-10-01']);

        $I->assertStringContainsString('bookings', $output);
        $I->assertStringContainsString('Metrics recalculated from 2026-10-01', $output);
        $I->assertSame(5, (int) $this->db($I)->fetchOne('SELECT COUNT(*) FROM raw_platform_booking'));

        // The nightly full run recalculates a year back: bookings changed long after their creation day.
        $output = $I->runSymfonyConsoleCommand('app:sync:platform', ['--full' => true, '--recalc-months' => '12']);
        $I->assertStringContainsString('Metrics recalculated from '.(new \DateTimeImmutable('first day of -12 months'))->format('Y-m-d'), $output);
        $output = $I->runSymfonyConsoleCommand('app:metrics:recalculate', ['--from' => '2026-10-01', '--months' => '12'], [], 2);
        $I->assertStringContainsString('cannot be combined', $output);
    }

    public function incrementalFiltersUseTheDateFormatsThePlatformAccepts(FunctionalTester $I): void
    {
        // Verified against the real platform API on 2026-11-05: each endpoint rejects the other formats.
        $this->handler($I)->handle(['bookings', 'housekeeping_tasks', 'tickets']);
        $this->handler($I)->handle(['bookings', 'housekeeping_tasks', 'tickets']);

        $last = fn (string $path): array => \array_slice($this->api->queriesFor($path), -1)[0];
        $I->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $last('/api/bookings')['lastModification']);
        $I->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $last('/api/housekeeping-tasks')['dueDateFrom']);
        $I->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $last('/api/tickets')['dateFrom']);
    }

    public function salesDatesComeFromDetailCardsOnFullRunsAndSurviveIncrementalOnes(FunctionalTester $I): void
    {
        // Like the real API: the list has no sales dates, only the detail card does.
        foreach (['/api/accommodations', '/api/accommodations?status=DELETED'] as $key) {
            foreach ($this->api->lists[$key] as $i => $item) {
                unset($this->api->lists[$key][$i]['salesActivationDate'], $this->api->lists[$key][$i]['salesDeactivationDate']);
            }
        }
        $this->api->accommodationDetails[1] = ['salesActivationDate' => '2026-02-01T00:00:00+01:00', 'salesDeactivationDate' => null];
        $this->api->failures['/api/accommodations/3'] = 404;

        $run = $this->handler($I)->handle(['accommodations'])[0];
        $I->assertSame(SyncStatus::Success, $run->getStatus(), 'a missing card does not fail the sync');
        $db = $this->db($I);
        $I->assertSame('2026-02-01', $db->fetchOne('SELECT sales_activation_date FROM raw_platform_accommodation WHERE id = 1'));
        $I->assertSame(3, (int) $db->fetchOne('SELECT COUNT(*) FROM raw_platform_accommodation'));

        $this->api->accommodationDetails[1]['salesActivationDate'] = '2026-03-01T00:00:00+01:00';
        $this->api->requests = [];
        $this->handler($I)->handle(['accommodations']);
        $I->assertSame([], $this->api->queriesFor('/api/accommodations/1'), 'incremental runs skip the detail cards');
        $I->assertSame('2026-02-01', $db->fetchOne('SELECT sales_activation_date FROM raw_platform_accommodation WHERE id = 1'), 'and keep the stored dates');

        $this->handler($I)->handle(['accommodations'], full: true);
        $I->assertSame('2026-03-01', $db->fetchOne('SELECT sales_activation_date FROM raw_platform_accommodation WHERE id = 1'));
    }

    public function availableNightsFallBackToGuestBookingsWithoutSalesDates(FunctionalTester $I): void
    {
        $this->api->lists['/api/accommodations'] = [
            $this->accommodation(1, 'ACTIVE', null, null),
            $this->accommodation(2, 'INACTIVE', null, null),
            $this->accommodation(4, 'ACTIVE', null, null),
        ];
        $this->api->lists['/api/accommodations?status=DELETED'] = [$this->accommodation(3, 'DELETED', null, null)];
        $this->api->lists['/api/bookings'] = [
            // A1: first guest booking created 01.11 Madrid; open-ended because ACTIVE.
            $this->booking(4, '2026-10-31T23:30:00+00:00', '50.00', 'CONFIRMED', '2026-10-30T00:00:00+01:00', '2026-11-03T00:00:00+01:00'),
            // A2: on sale from 20.10, last stay ends 04.11 (the later cancelled one does not extend it).
            ['accommodationId' => 2] + $this->booking(6, '2026-10-20T10:00:00+02:00', '300.00', 'PAID', '2026-10-28T00:00:00+01:00', '2026-11-04T00:00:00+01:00'),
            ['accommodationId' => 2] + $this->booking(7, '2026-10-21T10:00:00+02:00', '90.00', 'CANCELLED', '2026-11-20T00:00:00+01:00', '2026-11-25T00:00:00+01:00'),
            // A3: only a cancelled booking → zero nights. A4: only an owner stay → never on sale.
            ['accommodationId' => 3] + $this->booking(8, '2026-10-15T10:00:00+02:00', '80.00', 'CANCELLED', '2026-10-27T00:00:00+01:00', '2026-10-29T00:00:00+01:00'),
            ['accommodationId' => 4] + $this->booking(9, '2026-09-01T10:00:00+02:00', '0.00', 'OWNER', '2026-10-01T00:00:00+02:00', '2026-12-01T00:00:00+01:00'),
        ];
        $metrics = $this->revenueMetrics($I);
        $this->handler($I)->handle();
        // A5: the platform gave an activation date, but the listing is off sale and never had a guest → zero nights.
        $this->db($I)->insert('raw_platform_accommodation', [
            'id' => 5, 'name' => 'A5', 'status' => 'INACTIVE', 'sales_activation_date' => '2026-09-01',
            'payload' => '{}', 'synced_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $result = $this->recalculator($I)->recalculate(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-10'));
        $I->assertSame([], $result->errors);

        // 19–25.10: A2 from 20.10 (6); 26–31.10: A2 (6); 01.11: A1 + A2; 02–08.11: A1 7 + A2 02–03.11; 09–10.11: A1.
        $I->assertSame(
            ['2026-10-19' => '6', '2026-10-26' => '6', '2026-11-01' => '2', '2026-11-02' => '9', '2026-11-09' => '2'],
            $this->facts($I, $metrics['available'], ['2026-10-19', '2026-10-26', '2026-11-01', '2026-11-02', '2026-11-09']),
        );
    }

    /**
     * @return array<string, Metric>
     */
    private function revenueMetrics(FunctionalTester $I): array
    {
        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $unit = $tree->createUnit('Revenue');
        $em = $I->grabEntityManager();

        $metrics = [];
        foreach ([
            'sum' => ['t_bookings_sum', MetricUnit::Eur, ['calculator' => 'bookings_sum', 'date_field' => 'creation_date']],
            'arrivals' => ['t_arrivals', MetricUnit::Count, ['calculator' => 'bookings_count', 'date_field' => 'arrival_date']],
            'nights' => ['t_nights', MetricUnit::Count, ['calculator' => 'booked_nights']],
            'available' => ['t_available', MetricUnit::Count, ['calculator' => 'available_nights']],
            'cancelled' => ['t_cancelled', MetricUnit::Count, ['calculator' => 'bookings_count', 'date_field' => 'last_modification', 'statuses' => ['CANCELLED']]],
            'stayed' => ['t_stayed', MetricUnit::Eur, ['calculator' => 'stayed_revenue']],
            'checkin_nights' => ['t_checkin_nights', MetricUnit::Count, ['calculator' => 'booking_nights', 'date_field' => 'arrival_date']],
            'apt_checkin_sum' => ['t_apt_checkin_sum', MetricUnit::Eur, ['calculator' => 'bookings_sum', 'date_field' => 'arrival_date', 'accommodation_types' => ['APARTMENT', 'STUDIO']]],
            'villa_checkin_sum' => ['t_villa_checkin_sum', MetricUnit::Eur, ['calculator' => 'bookings_sum', 'date_field' => 'arrival_date', 'accommodation_types' => ['VILLA']]],
        ] as $key => [$code, $unitType, $config]) {
            $metric = new Metric($unit, $code, $code, $unitType);
            $metric->setSourceType(MetricSourceType::PlatformApi)->setSourceConfig($config);
            $em->persist($metric);
            $metrics[$key] = $metric;
        }

        $formula = new Metric($unit, 'rev_avg_booking', 'Avg', MetricUnit::Eur);
        $formula->setSourceType(MetricSourceType::PlatformApi)->setTimeAgg(TimeAggregation::Formula)->setFormula('t_bookings_sum / t_arrivals');
        $em->persist($formula);
        $em->flush();

        return $metrics;
    }

    /**
     * @param list<string> $starts
     *
     * @return array<string, string> period start => value without trailing zeros
     */
    private function facts(FunctionalTester $I, Metric $metric, array $starts): array
    {
        $rows = $this->db($I)->fetchAllKeyValue(
            'SELECT period_start, value FROM metric_value WHERE metric_id = ? AND period_type = ? ORDER BY period_start',
            [$metric->getId(), PeriodType::Week->value],
        );

        $facts = [];
        foreach ($starts as $start) {
            $value = $rows[$start] ?? null;
            $facts[$start] = null === $value ? 'missing' : rtrim(rtrim((string) $value, '0'), '.');
            if ('' === $facts[$start]) {
                $facts[$start] = '0';
            }
        }

        return $facts;
    }

    private function handler(FunctionalTester $I): PlatformSyncHandler
    {
        /** @var PlatformSyncHandler $handler */
        $handler = $I->grabService(PlatformSyncHandler::class);

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

    /**
     * @return array<string, mixed>
     */
    private function booking(int $id, string $created, string $total, string $status, ?string $arrival, ?string $departure, ?string $modified = null, string $currency = 'EUR'): array
    {
        return [
            'id' => $id, 'bookingReferenceNumber' => 'REF'.$id, 'agentReferenceNumber' => null,
            'status' => $status, 'portal' => 'Booking.com', 'total' => $total, 'currency' => $currency,
            'arrivalDate' => $arrival, 'departureDate' => $departure, 'creationDate' => $created,
            'lastModification' => $modified ?? $created, 'accommodationId' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accommodation(int $id, string $status, ?string $activation, ?string $deactivation): array
    {
        return [
            'id' => $id, 'name' => 'A'.$id, 'status' => $status, 'type' => 'APARTMENT', 'city' => 'Valencia',
            'category' => null, 'salesActivationDate' => $activation, 'salesDeactivationDate' => $deactivation,
        ];
    }

    public function portalFiltersSplitBookingsBySource(FunctionalTester $I): void
    {
        $bookings = &$this->api->lists['/api/bookings'];
        $bookings[0]['portal'] = 'Manual';
        // Portal names compare case- and accent-insensitively (column collation): a renamed spelling still matches.
        $bookings[1]['portal'] = 'MANUAL prolongacion';
        unset($bookings[3]['portal']); // like the real API: no key at all
        unset($bookings);

        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $unit = $tree->createUnit('CS');
        $em = $I->grabEntityManager();
        /** @var array<string, Metric> $metrics */
        $metrics = [];
        foreach ([
            'direct' => ['bookings_sum', ['portals' => ['Manual', 'Manual robo', 'Manual prolongación']]],
            'manual' => ['bookings_count', ['portals' => ['Manual']]],
            'not_manual' => ['bookings_sum', ['exclude_portals' => ['Manual']]],
        ] as $code => [$calculator, $filter]) {
            $metric = new Metric($unit, $code, $code, 'bookings_sum' === $calculator ? MetricUnit::Eur : MetricUnit::Count);
            $metric->setSourceType(MetricSourceType::PlatformApi)->setSourceConfig(['calculator' => $calculator, 'date_field' => 'creation_date'] + $filter);
            $em->persist($metric);
            $metrics[$code] = $metric;
        }
        $em->flush();
        $this->handler($I)->handle(['bookings']);

        $result = $this->recalculator($I)->recalculate(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-10'));
        $I->assertSame([], $result->errors);

        $weeks = ['2026-11-01', '2026-11-02'];
        // B1 Manual (01.11), B2 Manual prolongación (02.11); B4 has no portal.
        $I->assertSame(['2026-11-01' => '100', '2026-11-02' => '250.5'], $this->facts($I, $metrics['direct'], $weeks));
        $I->assertSame(['2026-11-01' => '1', '2026-11-02' => '0'], $this->facts($I, $metrics['manual'], $weeks));
        // Excluding a portal keeps bookings without one.
        $I->assertSame(['2026-11-01' => '50', '2026-11-02' => '250.5'], $this->facts($I, $metrics['not_manual'], $weeks));
    }

    public function aBrokenBookingCardDoesNotStopTheSync(FunctionalTester $I): void
    {
        $this->api->bookingDetails = [1 => ['rentalPrice' => '80.00', 'portalCommission' => '12.00']];
        // The card of booking 2 keeps failing even after the client's retries.
        $this->api->failures['/api/bookings/2'] = 500;

        $run = $this->handler($I)->handle(['bookings'])[0];
        $I->assertSame(SyncStatus::Success, $run->getStatus(), 'one broken card does not fail the resource');
        $db = $this->db($I);
        $I->assertSame('80.00', $db->fetchOne('SELECT rental_price FROM raw_platform_booking WHERE id = 1'));
        $I->assertNotFalse($db->fetchOne('SELECT id FROM raw_platform_booking WHERE id = 2'), 'the list row itself is stored');
        $I->assertNull($db->fetchOne('SELECT rental_price FROM raw_platform_booking WHERE id = 2'));
    }

    public function rentalPriceComesFromDetailCardsOfNewAndChangedBookings(FunctionalTester $I): void
    {
        $this->api->lists['/api/bookings'][] = $this->booking(6, '2026-11-02T12:00:00+01:00', '0.00', 'OWNER', '2026-11-20T00:00:00+01:00', '2026-11-25T00:00:00+01:00');
        $this->api->bookingDetails = [
            1 => ['rentalPrice' => '80.00', 'portalCommission' => '12.00', 'client' => ['name' => 'Guest']],
            2 => ['rentalPrice' => '200.25', 'portalCommission' => '30.00'],
            4 => ['rentalPrice' => '40.00', 'portalCommission' => null],
        ];
        $this->api->failures['/api/bookings/5'] = 404;

        $run = $this->handler($I)->handle(['bookings'])[0];
        $I->assertSame(SyncStatus::Success, $run->getStatus(), 'a missing card does not fail the sync');

        $db = $this->db($I);
        $I->assertSame(['80.00', '12.00'], array_values($db->fetchAssociative('SELECT rental_price, portal_commission FROM raw_platform_booking WHERE id = 1') ?: []));
        $I->assertNull($db->fetchOne('SELECT rental_price FROM raw_platform_booking WHERE id = 5'));
        $I->assertSame([], $this->api->queriesFor('/api/bookings/6'), 'owner stays have no price card');
        $I->assertStringNotContainsString('Guest', (string) $db->fetchOne('SELECT payload FROM raw_platform_booking WHERE id = 1'), 'only prices are taken from the card');

        // Unchanged bookings keep their stored prices without a new card request; a changed one is fetched again.
        $this->api->requests = [];
        $this->api->lists['/api/bookings'][1]['lastModification'] = '2026-11-05T10:00:00+01:00';
        $this->api->bookingDetails[2]['rentalPrice'] = '210.00';
        $this->handler($I)->handle(['bookings'], full: true);

        $I->assertSame([], $this->api->queriesFor('/api/bookings/1'));
        $I->assertCount(1, $this->api->queriesFor('/api/bookings/2'));
        $I->assertCount(1, $this->api->queriesFor('/api/bookings/5'), 'a missing card is retried');
        $I->assertSame('80.00', $db->fetchOne('SELECT rental_price FROM raw_platform_booking WHERE id = 1'));
        $I->assertSame('210.00', $db->fetchOne('SELECT rental_price FROM raw_platform_booking WHERE id = 2'));

        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $metric = new Metric($tree->createUnit('CS'), 't_rent', 't_rent', MetricUnit::Eur);
        $metric->setSourceType(MetricSourceType::PlatformApi)->setSourceConfig(['calculator' => 'bookings_sum', 'date_field' => 'creation_date', 'amount_field' => 'rental_price']);
        $em = $I->grabEntityManager();
        $em->persist($metric);
        $em->flush();

        $result = $this->recalculator($I)->recalculate(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-10'));
        $I->assertSame([], $result->errors);
        // 01.11: B1 80 + B4 40; 02.11: B2 210 (B5 is USD, B6 an owner stay).
        $I->assertSame(['2026-11-01' => '120', '2026-11-02' => '210'], $this->facts($I, $metric, ['2026-11-01', '2026-11-02']));
    }

    public function unknownAmountFieldIsRejected(FunctionalTester $I): void
    {
        $I->expectThrowable(\InvalidArgumentException::class, static fn () => PlatformMetricConfig::fromArray(['calculator' => 'bookings_sum', 'amount_field' => 'payload']));
    }

    public function stayLengthArrivalMonthAndCancellationDateFilterBookings(FunctionalTester $I): void
    {
        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $unit = $tree->createUnit('Revenue');
        $em = $I->grabEntityManager();
        $metrics = [];
        foreach ([
            'long' => ['calculator' => 'bookings_count', 'date_field' => 'creation_date', 'min_nights' => 4],
            'same_month' => ['calculator' => 'bookings_count', 'date_field' => 'creation_date', 'arrival_month_offset' => 0],
            'next_month' => ['calculator' => 'bookings_count', 'date_field' => 'creation_date', 'arrival_month_offset' => 1],
            'cancelled_sum' => ['calculator' => 'bookings_sum', 'date_field' => 'cancelled_at', 'statuses' => ['CANCELLED']],
        ] as $key => $config) {
            $metric = new Metric($unit, 't_'.$key, $key, MetricUnit::Count);
            $metric->setSourceType(MetricSourceType::PlatformApi)->setSourceConfig($config);
            $em->persist($metric);
            $metrics[$key] = $metric;
        }
        $em->flush();

        $this->handler($I)->handle();
        $result = $this->recalculator($I)->recalculate(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-10'));
        $I->assertSame([], $result->errors);

        $starts = ['2026-11-01', '2026-11-02'];
        // Only B4 (created 01.11 in Madrid) stays 4 nights; B1 and B2 stay 3.
        $I->assertSame(['2026-11-01' => '1', '2026-11-02' => '0'], $this->facts($I, $metrics['long'], $starts));
        // Created in November: B1 and B2 arrive in November, B4 arrived in October.
        $I->assertSame(['2026-11-01' => '1', '2026-11-02' => '1'], $this->facts($I, $metrics['same_month'], $starts));
        $I->assertSame(['2026-11-01' => '0', '2026-11-02' => '0'], $this->facts($I, $metrics['next_month'], $starts));
        // B3 was first seen cancelled with lastModification 04.11.
        $I->assertSame(['2026-11-01' => '0', '2026-11-02' => '999'], $this->facts($I, $metrics['cancelled_sum'], $starts));
    }

    public function invalidStayLengthIsRejected(FunctionalTester $I): void
    {
        $I->expectThrowable(\InvalidArgumentException::class, static fn () => PlatformMetricConfig::fromArray(['calculator' => 'bookings_count', 'min_nights' => '14']));
        $I->expectThrowable(\InvalidArgumentException::class, static fn () => PlatformMetricConfig::fromArray(['calculator' => 'bookings_count', 'arrival_month_offset' => -1]));
    }

    public function ownerAndBlockedNightsCountOnlyInsideTheSalesWindow(FunctionalTester $I): void
    {
        $this->api->lists['/api/accommodations'] = [
            $this->accommodation(1, 'ACTIVE', null, null),
            $this->accommodation(2, 'INACTIVE', null, null),
            $this->accommodation(4, 'ACTIVE', null, null),
        ];
        $this->api->lists['/api/accommodations?status=DELETED'] = [];
        $this->api->lists['/api/bookings'] = [
            // A1: on sale from 01.11 Madrid (creation of its first guest booking), open-ended.
            $this->booking(4, '2026-10-31T23:30:00+00:00', '50.00', 'CONFIRMED', '2026-11-05T00:00:00+01:00', '2026-11-07T00:00:00+01:00'),
            // A2: on sale from 20.10 until its last stay ends on 04.11.
            ['accommodationId' => 2] + $this->booking(6, '2026-10-20T10:00:00+02:00', '300.00', 'PAID', '2026-10-28T00:00:00+01:00', '2026-11-04T00:00:00+01:00'),
            // Owner stay on A1, 30.10–03.11: only the nights of 01.11 and 02.11 are inside the window.
            $this->booking(10, '2026-10-01T10:00:00+02:00', '0.00', 'OWNER', '2026-10-30T00:00:00+01:00', '2026-11-03T00:00:00+01:00'),
            // Owner stay on A4, which was never on sale: no capacity, no owner nights.
            ['accommodationId' => 4] + $this->booking(9, '2026-09-01T10:00:00+02:00', '0.00', 'OWNER', '2026-10-01T00:00:00+02:00', '2026-12-01T00:00:00+01:00'),
            // Block on A2, 02.11–10.11: only 02.11 and 03.11 are before the listing went off sale.
            ['accommodationId' => 2] + $this->booking(11, '2026-10-25T10:00:00+01:00', '0.00', 'UNAVAILABLE', '2026-11-02T00:00:00+01:00', '2026-11-10T00:00:00+01:00'),
        ];

        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $unit = $tree->createUnit('Revenue');
        $em = $I->grabEntityManager();
        $metrics = [];
        foreach ([
            'owner' => ['calculator' => 'booked_nights', 'statuses' => ['OWNER'], 'sellable_only' => true],
            'blocked' => ['calculator' => 'booked_nights', 'statuses' => ['UNAVAILABLE'], 'sellable_only' => true],
            'owner_everywhere' => ['calculator' => 'booked_nights', 'statuses' => ['OWNER']],
        ] as $key => $config) {
            $metric = new Metric($unit, 't_'.$key, $key, MetricUnit::Count);
            $metric->setSourceType(MetricSourceType::PlatformApi)->setSourceConfig($config);
            $em->persist($metric);
            $metrics[$key] = $metric;
        }
        $em->flush();

        $this->handler($I)->handle();
        $result = $this->recalculator($I)->recalculate(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-10'));
        $I->assertSame([], $result->errors);

        $starts = ['2026-10-26', '2026-11-01', '2026-11-02', '2026-11-09'];
        $I->assertSame(['2026-10-26' => '0', '2026-11-01' => '1', '2026-11-02' => '1', '2026-11-09' => '0'], $this->facts($I, $metrics['owner'], $starts));
        $I->assertSame(['2026-10-26' => '0', '2026-11-01' => '0', '2026-11-02' => '2', '2026-11-09' => '0'], $this->facts($I, $metrics['blocked'], $starts));
        // Without the option: A1 30–31.10 and A4 every night.
        $I->assertSame(['2026-10-26' => '8', '2026-11-01' => '2', '2026-11-02' => '8', '2026-11-09' => '2'], $this->facts($I, $metrics['owner_everywhere'], $starts));
    }

    public function forwardOccupancyIsAForecastAndListingsSplitByTheirOccupancy(FunctionalTester $I): void
    {
        $this->api->lists['/api/accommodations'] = [
            $this->accommodation(1, 'ACTIVE', null, null),
            $this->accommodation(2, 'ACTIVE', null, null),
            $this->accommodation(3, 'ACTIVE', null, null),
        ];
        $this->api->lists['/api/accommodations?status=DELETED'] = [];
        $this->api->lists['/api/bookings'] = [
            // A1 and A2 are on sale from 01.10 (their first guest bookings); A3 never was (only an owner stay).
            $this->booking(1, '2026-10-01T10:00:00+02:00', '300.00', 'CONFIRMED', '2026-11-03T00:00:00+01:00', '2026-11-06T00:00:00+01:00'),
            ['accommodationId' => 2] + $this->booking(2, '2026-10-01T11:00:00+02:00', '400.00', 'CONFIRMED', '2026-11-09T00:00:00+01:00', '2026-11-13T00:00:00+01:00'),
            $this->booking(3, '2026-10-02T10:00:00+02:00', '0.00', 'OWNER', '2026-11-12T00:00:00+01:00', '2026-11-20T00:00:00+01:00'),
            ['accommodationId' => 3] + $this->booking(4, '2026-09-01T10:00:00+02:00', '0.00', 'OWNER', '2026-10-01T00:00:00+02:00', '2026-12-01T00:00:00+01:00'),
        ];

        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $unit = $tree->createUnit('Revenue');
        $em = $I->grabEntityManager();
        $taken = ['statuses' => ['CONFIRMED', 'PAID', 'OWNER']];
        /** @var array<string, Metric> $metrics */
        $metrics = [];
        foreach ([
            'next5_taken' => [TimeAggregation::Last, ['calculator' => 'booked_nights', 'sellable_only' => true, 'forward_days' => 5, ...$taken]],
            'next5_capacity' => [TimeAggregation::Last, ['calculator' => 'available_nights', 'forward_days' => 5]],
            'green' => [TimeAggregation::Avg, ['calculator' => 'objects_by_occupancy', 'band' => 'green', ...$taken]],
            'yellow' => [TimeAggregation::Avg, ['calculator' => 'objects_by_occupancy', 'band' => 'yellow', ...$taken]],
            'red' => [TimeAggregation::Avg, ['calculator' => 'objects_by_occupancy', 'band' => 'red', ...$taken]],
            'all' => [TimeAggregation::Avg, ['calculator' => 'objects_by_occupancy', 'band' => 'all']],
        ] as $key => [$agg, $config]) {
            $metric = new Metric($unit, 't_'.$key, $key, MetricUnit::Count);
            $metric->setSourceType(MetricSourceType::PlatformApi)->setTimeAgg($agg)->setSourceConfig($config);
            $em->persist($metric);
            $metrics[$key] = $metric;
        }
        $em->flush();

        $this->handler($I)->handle();
        $result = $this->recalculator($I)->recalculate(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-10'));
        $I->assertSame([], $result->errors);
        $I->assertSame([], $result->skipped, 'avg and last metrics with a calculator are computed');

        $weeks = ['2026-11-02', '2026-11-09'];
        // 02–08.11: A1 has 3 of 7 nights (below 60 %), A2 none. 09–10.11: A2 both nights, A1 none.
        $I->assertSame(['2026-11-02' => '0', '2026-11-09' => '1'], $this->facts($I, $metrics['green'], $weeks));
        $I->assertSame(['2026-11-02' => '1', '2026-11-09' => '0'], $this->facts($I, $metrics['yellow'], $weeks));
        $I->assertSame(['2026-11-02' => '1', '2026-11-09' => '1'], $this->facts($I, $metrics['red'], $weeks));
        $I->assertSame(['2026-11-02' => '2', '2026-11-09' => '2'], $this->facts($I, $metrics['all'], $weeks));

        // Forecast as of 10.11 for 11–15.11: A2 nights 11 and 12, the owner in A1 on 12–15 → 6 of 2 × 5 nights.
        // Finished weeks get no forecast: nobody knows any more what was booked back then.
        $I->assertSame(['2026-11-02' => 'missing', '2026-11-09' => '6'], $this->facts($I, $metrics['next5_taken'], $weeks));
        $I->assertSame(['2026-11-02' => 'missing', '2026-11-09' => '10'], $this->facts($I, $metrics['next5_capacity'], $weeks));

        // A week later: the new week gets its own forecast (18–22.11: the owner on 18 and 19), the old one stays.
        $this->recalculator($I)->recalculate(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-17'));
        $I->assertSame(['2026-11-09' => '6', '2026-11-16' => '2'], $this->facts($I, $metrics['next5_taken'], ['2026-11-09', '2026-11-16']));
    }

    public function excludedAccommodationsCountNowhere(FunctionalTester $I): void
    {
        $this->api->lists['/api/accommodations'] = [
            $this->accommodation(1, 'ACTIVE', null, null),
            $this->accommodation(2, 'ACTIVE', null, null),
        ];
        $this->api->lists['/api/accommodations?status=DELETED'] = [];
        $this->api->lists['/api/bookings'] = [
            // A1 and the test listing A2 are both on sale from 01.10 and have a stay on 03–06.11.
            $this->booking(1, '2026-10-01T10:00:00+02:00', '300.00', 'CONFIRMED', '2026-11-03T00:00:00+01:00', '2026-11-06T00:00:00+01:00'),
            ['accommodationId' => 2] + $this->booking(2, '2026-10-01T11:00:00+02:00', '400.00', 'CONFIRMED', '2026-11-03T00:00:00+01:00', '2026-11-06T00:00:00+01:00'),
            ['accommodationId' => 2] + $this->booking(3, '2026-11-04T11:00:00+01:00', '70.00', 'PAID', '2026-12-03T00:00:00+01:00', '2026-12-06T00:00:00+01:00'),
            // A booking without an accommodation still counts.
            ['accommodationId' => null] + $this->booking(4, '2026-11-05T11:00:00+01:00', '20.00', 'PAID', '2026-12-03T00:00:00+01:00', '2026-12-04T00:00:00+01:00'),
        ];
        $metrics = $this->revenueMetrics($I);
        $this->handler($I)->handle();

        /** @var ExcludedAccommodations $excluded */
        $excluded = $I->grabService(ExcludedAccommodations::class);
        $week = ['2026-11-02'];
        $from = new \DateTimeImmutable('2026-11-01');
        $to = new \DateTimeImmutable('2026-11-10');

        $this->recalculator($I)->recalculate($from, $to);
        $I->assertSame(['2026-11-02' => '90'], $this->facts($I, $metrics['sum'], $week));
        $I->assertSame(['2026-11-02' => '6'], $this->facts($I, $metrics['nights'], $week));
        $I->assertSame(['2026-11-02' => '14'], $this->facts($I, $metrics['available'], $week));

        $I->assertTrue($excluded->add(2, 'test listing'));
        $I->assertFalse($excluded->add(2, 'still a test listing'));
        $I->expectThrowable(\InvalidArgumentException::class, static fn () => $excluded->add(999, null));

        $result = $this->recalculator($I)->recalculate($from, $to);
        $I->assertSame([], $result->errors);
        $I->assertSame(['2026-11-02' => '20'], $this->facts($I, $metrics['sum'], $week));
        $I->assertSame(['2026-11-02' => '1'], $this->facts($I, $metrics['arrivals'], $week));
        $I->assertSame(['2026-11-02' => '3'], $this->facts($I, $metrics['nights'], $week));
        $I->assertSame(['2026-11-02' => '7'], $this->facts($I, $metrics['available'], $week));
        $I->assertSame(['2026-11-02' => '300'], $this->facts($I, $metrics['stayed'], $week));
        $I->assertSame(['still a test listing'], array_column($excluded->list(), 'note'));

        $I->assertTrue($excluded->remove(2));
        $this->recalculator($I)->recalculate($from, $to);
        $I->assertSame(['2026-11-02' => '14'], $this->facts($I, $metrics['available'], $week));
    }

    public function forwardDaysAndBandsBelongToTheirCalculators(FunctionalTester $I): void
    {
        $I->expectThrowable(\InvalidArgumentException::class, static fn () => PlatformMetricConfig::fromArray(['calculator' => 'bookings_count', 'forward_days' => 5]));
        $I->expectThrowable(\InvalidArgumentException::class, static fn () => PlatformMetricConfig::fromArray(['calculator' => 'objects_by_occupancy']));
        $I->expectThrowable(\InvalidArgumentException::class, static fn () => PlatformMetricConfig::fromArray(['calculator' => 'objects_by_occupancy', 'band' => 'blue']));
        $I->expectThrowable(\InvalidArgumentException::class, static fn () => PlatformMetricConfig::fromArray(['calculator' => 'booked_nights', 'band' => 'red']));
    }

    public function sellableOnlyIsForBookedNights(FunctionalTester $I): void
    {
        $I->expectThrowable(\InvalidArgumentException::class, static fn () => PlatformMetricConfig::fromArray(['calculator' => 'bookings_count', 'sellable_only' => true]));
        $I->expectThrowable(\InvalidArgumentException::class, static fn () => PlatformMetricConfig::fromArray(['calculator' => 'booked_nights', 'sellable_only' => 'yes']));
    }
}
