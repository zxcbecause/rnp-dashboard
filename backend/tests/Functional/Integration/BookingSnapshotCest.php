<?php

declare(strict_types=1);

namespace App\Tests\Functional\Integration;

use App\Integration\Platform\BookingDaySnapshotter;
use App\Service\Metric\Calculation\PlatformMetricCalculator;
use App\Service\Metric\Calculation\PlatformMetricConfig;
use App\Tests\Support\FunctionalTester;
use BcMath\Number;
use Doctrine\DBAL\Connection;

/**
 * Nightly snapshot of the bookings created on a day: robo 1 is still confirmed in the evening and cancelled days
 * later, which only the snapshot remembers.
 */
final class BookingSnapshotCest
{
    public function snapshotKeepsTheDayAsItWasAndReplacesItsOwnRows(FunctionalTester $I): void
    {
        /** @var Connection $db */
        $db = $I->grabService(Connection::class);
        $booking = static fn (int $id, string $created, string $status, string $portal, string $price) => $db->insert('raw_platform_booking', [
            'id' => $id, 'status' => $status, 'portal' => $portal, 'total' => $price, 'rental_price' => $price, 'currency' => 'EUR',
            'creation_date' => $created, 'arrival_date' => '2026-11-20 00:00:00', 'payload' => '{}', 'synced_at' => '2026-11-05 23:50:00',
        ]);
        $booking(1, '2026-11-05 19:36:00', 'CONFIRMED', 'Manual robo', '2431.49');
        $booking(2, '2026-11-05 00:00:00', 'PAID', 'Booking.com', '183.98');
        $booking(3, '2026-11-06 00:00:00', 'PAID', 'Booking.com', '100.00');
        $booking(4, '2026-11-04 23:59:59', 'PAID', 'Booking.com', '50.00');

        /** @var BookingDaySnapshotter $snapshotter */
        $snapshotter = $I->grabService(BookingDaySnapshotter::class);
        $evening = new \DateTimeImmutable('2026-11-05 23:55:00', new \DateTimeZone('Europe/Madrid'));
        $I->assertSame(2, $snapshotter->take(new \DateTimeImmutable('2026-11-05'), $evening));

        // Cancelled four days later: the platform now says CANCELLED, the snapshot of 05.11 still CONFIRMED.
        $db->update('raw_platform_booking', ['status' => 'CANCELLED'], ['id' => 1]);
        $I->assertSame(
            [['booking_id' => 1, 'status' => 'CONFIRMED', 'rental_price' => '2431.49', 'taken_at' => '2026-11-05 23:55:00'], ['booking_id' => 2, 'status' => 'PAID', 'rental_price' => '183.98', 'taken_at' => '2026-11-05 23:55:00']],
            $this->rows($db, '2026-11-05'),
        );

        // Taking the same day again replaces its rows and leaves other days alone.
        $I->assertSame(1, $snapshotter->take(new \DateTimeImmutable('2026-11-06'), $evening->modify('+1 day')));
        $I->assertSame(2, $snapshotter->take(new \DateTimeImmutable('2026-11-05'), $evening->modify('+1 hour')));
        $I->assertSame('CANCELLED', $this->rows($db, '2026-11-05')[0]['status']);
        $I->assertCount(1, $this->rows($db, '2026-11-06'));
    }

    public function snapshotMetricsCountTheDayAsItWasInTheEvening(FunctionalTester $I): void
    {
        /** @var Connection $db */
        $db = $I->grabService(Connection::class);
        $booking = static fn (int $id, string $created, string $status, string $portal, string $price) => $db->insert('raw_platform_booking', [
            'id' => $id, 'status' => $status, 'portal' => $portal, 'total' => $price, 'rental_price' => $price, 'currency' => 'EUR',
            'creation_date' => $created, 'arrival_date' => '2026-11-20 00:00:00', 'departure_date' => '2026-11-25 00:00:00',
            'payload' => '{}', 'synced_at' => '2026-11-05 23:50:00',
        ]);
        $booking(1, '2026-11-05 19:36:00', 'CONFIRMED', 'Manual robo', '2431.49');
        $booking(2, '2026-11-04 12:00:00', 'CONFIRMED', 'Manual robo', '100.00');

        /** @var BookingDaySnapshotter $snapshotter */
        $snapshotter = $I->grabService(BookingDaySnapshotter::class);
        $snapshotter->take(new \DateTimeImmutable('2026-11-05'), new \DateTimeImmutable('2026-11-05 23:55:00', new \DateTimeZone('Europe/Madrid')));
        // Both are cancelled later; only 05.11 has an evening snapshot.
        $db->update('raw_platform_booking', ['status' => 'CANCELLED'], ['id' => 1]);
        $db->update('raw_platform_booking', ['status' => 'CANCELLED'], ['id' => 2]);

        /** @var PlatformMetricCalculator $calculator */
        $calculator = $I->grabService(PlatformMetricCalculator::class);
        $sum = static fn (array $config): string => (string) $calculator->calculate(
            PlatformMetricConfig::fromArray($config) ?? throw new \LogicException('config'),
            new \DateTimeImmutable('2026-11-02'),
            new \DateTimeImmutable('2026-11-09'),
        );
        $robo = ['calculator' => 'bookings_sum', 'amount_field' => 'rental_price', 'portals' => ['Manual robo']];

        $I->assertSame(0, (new Number($sum($robo)))->compare(0), 'current state: both cancelled');
        $I->assertSame(0, (new Number($sum([...$robo, 'day_snapshot' => true])))->compare('2431.49'), '05.11 as in the evening; 04.11 has no snapshot');
        $I->assertSame(0, $calculator->calculate(
            PlatformMetricConfig::fromArray([...$robo, 'calculator' => 'bookings_count', 'day_snapshot' => true]) ?? throw new \LogicException('config'),
            new \DateTimeImmutable('2026-11-05'),
            new \DateTimeImmutable('2026-11-06'),
        )->compare(1));

        $I->expectThrowable(\InvalidArgumentException::class, static fn () => PlatformMetricConfig::fromArray(['calculator' => 'bookings_sum', 'date_field' => 'arrival_date', 'day_snapshot' => true]));
    }

    public function scheduledRunActsOnlyLateInTheMadridEvening(FunctionalTester $I): void
    {
        /** @var BookingDaySnapshotter $snapshotter */
        $snapshotter = $I->grabService(BookingDaySnapshotter::class);
        $utc = static fn (string $time) => new \DateTimeImmutable($time, new \DateTimeZone('UTC'));

        // Winter (UTC+1): 22:55 UTC = 23:55 Madrid acts, 21:55 UTC = 22:55 does not.
        $I->assertSame('2026-11-10', $snapshotter->scheduledDay($utc('2026-11-10 22:55:00'))?->format('Y-m-d'));
        $I->assertNull($snapshotter->scheduledDay($utc('2026-11-10 21:55:00')));
        // Summer (UTC+2): 21:55 UTC = 23:55 Madrid acts, 22:55 UTC = 00:55 next day does not.
        $I->assertSame('2026-07-10', $snapshotter->scheduledDay($utc('2026-07-10 21:55:00'))?->format('Y-m-d'));
        $I->assertNull($snapshotter->scheduledDay($utc('2026-07-10 22:55:00')));
    }

    public function commandSnapshotsTheGivenDay(FunctionalTester $I): void
    {
        /** @var Connection $db */
        $db = $I->grabService(Connection::class);
        $db->insert('raw_platform_booking', ['id' => 7, 'status' => 'PAID', 'portal' => 'Manual', 'creation_date' => '2026-11-03 12:00:00', 'payload' => '{}', 'synced_at' => '2026-11-03 12:00:00']);

        $output = $I->runSymfonyConsoleCommand('app:bookings:snapshot', ['--date' => '2026-11-03', '--skip-sync' => true]);
        $I->assertStringContainsString('Snapshot of 2026-11-03: 1 booking(s).', $output);
        $I->assertCount(1, $this->rows($db, '2026-11-03'));

        $I->runSymfonyConsoleCommand('app:bookings:snapshot', ['--date' => '2026-13-01'], [], 2);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(Connection $db, string $day): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $db->fetchAllAssociative('SELECT booking_id, status, rental_price, taken_at FROM booking_day_snapshot WHERE snapshot_date = ? ORDER BY booking_id', [$day]);

        return array_map(static fn (array $r): array => [...$r, 'booking_id' => (int) $r['booking_id']], $rows);
    }
}
