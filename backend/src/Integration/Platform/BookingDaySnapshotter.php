<?php

declare(strict_types=1);

namespace App\Integration\Platform;

use Doctrine\DBAL\Connection;

/**
 * Copies the bookings created on a day, as they are now, into booking_day_snapshot (see the entity). Taken every
 * evening at 23:55 Madrid it matches Gleb's nightly Avantio export; taking the same day again replaces its rows.
 */
final readonly class BookingDaySnapshotter
{
    private const string TIMEZONE = 'Europe/Madrid';

    /** The evening window in which the scheduled run takes the snapshot of "today". */
    private const string WINDOW_FROM = '23:30';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return int bookings in the snapshot
     */
    public function take(\DateTimeImmutable $day, ?\DateTimeImmutable $now = null): int
    {
        $start = $day->setTime(0, 0);
        $takenAt = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))
            ->setTimezone(new \DateTimeZone(self::TIMEZONE));

        return $this->connection->transactional(function (Connection $db) use ($start, $takenAt): int {
            $db->executeStatement('DELETE FROM booking_day_snapshot WHERE snapshot_date = ?', [$start->format('Y-m-d')]);

            return (int) $db->executeStatement(
                'INSERT INTO booking_day_snapshot
                    (snapshot_date, booking_id, status, portal, total, rental_price, currency, creation_date, arrival_date,
                     departure_date, last_modification, accommodation_id, taken_at)
                 SELECT :day, id, status, portal, total, rental_price, currency, creation_date, arrival_date,
                     departure_date, last_modification, accommodation_id, :takenAt
                 FROM raw_platform_booking
                 WHERE creation_date >= :from AND creation_date < :to',
                [
                    'day' => $start->format('Y-m-d'),
                    'takenAt' => $takenAt->format('Y-m-d H:i:s'),
                    'from' => $start->format('Y-m-d'),
                    'to' => $start->modify('+1 day')->format('Y-m-d'),
                ],
            );
        });
    }

    /**
     * Madrid day whose snapshot a scheduled run takes now, or null outside the evening window. The scheduler works
     * in UTC and Madrid moves between UTC+1 and UTC+2, so the job fires at both candidate hours and only the run
     * that falls into 23:30–23:59 Madrid acts.
     */
    public function scheduledDay(?\DateTimeImmutable $now = null): ?\DateTimeImmutable
    {
        $local = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone(self::TIMEZONE));

        return $local->format('H:i') >= self::WINDOW_FROM ? $local->setTime(0, 0) : null;
    }
}
