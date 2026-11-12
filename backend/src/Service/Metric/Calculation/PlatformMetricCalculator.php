<?php

declare(strict_types=1);

namespace App\Service\Metric\Calculation;

use App\Enum\MetricSourceType;
use BcMath\Number;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Computes the value of a platform metric for [start, end) from the raw_platform_* snapshots.
 * Dates are Madrid wall time; `end` is exclusive.
 * Accommodations listed in excluded_accommodation (test listings) are left out of every calculator: neither their
 * bookings nor their capacity count.
 */
final readonly class PlatformMetricCalculator implements SourceMetricCalculator
{
    /** Platform booking statuses that are not guest stays: owner use and calendar blocks. */
    private const array NOT_GUEST_STATUSES = ['OWNER', 'UNAVAILABLE'];

    /** Guest bookings that never turned into a stay. */
    private const array INACTIVE_GUEST_STATUSES = ['CANCELLED', 'REQUESTED', 'UNDER_REQUEST', 'CONFLICTED'];

    /**
     * day_snapshot: bookings of the days with an evening snapshot as they were then, the other days as they are now.
     * Only the columns the booking filters use (no cancelled_at: snapshot metrics never filter on it).
     */
    private const string DAY_SNAPSHOT_SOURCE = '(
        SELECT s.status, s.portal, s.total, s.rental_price, s.currency, s.creation_date, s.arrival_date, s.departure_date, s.accommodation_id
        FROM booking_day_snapshot s
        WHERE s.snapshot_date >= :start AND s.snapshot_date < :end
        UNION ALL
        SELECT r.status, r.portal, r.total, r.rental_price, r.currency, r.creation_date, r.arrival_date, r.departure_date, r.accommodation_id
        FROM raw_platform_booking r
        WHERE r.creation_date >= :start AND r.creation_date < :end
          AND DATE(r.creation_date) NOT IN (
              SELECT d.snapshot_date FROM booking_day_snapshot d WHERE d.snapshot_date >= :start AND d.snapshot_date < :end
          )
    )';

    /**
     * Sales window of every accommodation that was ever on sale: [sell_from, sell_to), sell_to NULL = still on sale.
     *
     * The platform does not fill the sales dates yet, so missing ones are inferred from guest bookings
     * (owner stays and blocks excluded):
     * - activation = creation date of the first guest booking (any status: it proves the listing was on sale);
     * - deactivation = for a non-ACTIVE accommodation, the departure of its last non-cancelled guest booking
     *   (no such booking → its activation day, i.e. zero nights — also when the platform gave only an
     *   activation date); ACTIVE ones stay open-ended.
     * An accommodation with neither an activation date nor a guest booking has no window.
     * Needs the parameters :not_guest and :inactive (see salesWindowParameters()).
     */
    private const string SALES_WINDOW = '(
        SELECT a.id,
               COALESCE(a.sales_activation_date, g.first_created) AS sell_from,
               COALESCE(a.sales_deactivation_date, IF(a.status = \'ACTIVE\', NULL, COALESCE(g.last_departure, g.first_created, a.sales_activation_date))) AS sell_to
        FROM raw_platform_accommodation a
        LEFT JOIN (
            SELECT b.accommodation_id,
                   DATE(MIN(b.creation_date)) AS first_created,
                   DATE(MAX(IF(b.status IN (:inactive), NULL, b.departure_date))) AS last_departure
            FROM raw_platform_booking b
            WHERE b.status NOT IN (:not_guest) AND b.accommodation_id IS NOT NULL
            GROUP BY b.accommodation_id
        ) g ON g.accommodation_id = a.id
        WHERE COALESCE(a.sales_activation_date, g.first_created) IS NOT NULL
          AND a.id NOT IN (SELECT x.accommodation_id FROM excluded_accommodation x)
    )';

    /** Bookings of excluded accommodations (test listings) never count; a booking without an accommodation does. */
    private const string NOT_EXCLUDED = ' AND (b.accommodation_id IS NULL OR b.accommodation_id NOT IN (SELECT x.accommodation_id FROM excluded_accommodation x))';

    public function __construct(private Connection $connection)
    {
    }

    public function calculate(object $config, \DateTimeImmutable $start, \DateTimeImmutable $end): Number
    {
        $config = $this->config($config);
        if (null !== $config->forwardDays) {
            // A forecast as of the last day of the period: the nights of the days that follow it.
            [$start, $end] = [$end, $end->modify(\sprintf('+%d days', $config->forwardDays))];
        }

        return match ($config->calculator) {
            // amount_field is whitelisted by PlatformMetricConfig.
            PlatformMetricConfig::BOOKINGS_SUM => $this->bookings($config, $start, $end, \sprintf('COALESCE(SUM(b.%s), 0)', $config->amountField)),
            PlatformMetricConfig::BOOKINGS_COUNT => $this->bookings($config, $start, $end, 'COUNT(*)'),
            PlatformMetricConfig::BOOKED_NIGHTS => $this->bookedNights($config, $start, $end),
            PlatformMetricConfig::AVAILABLE_NIGHTS => $this->availableNights($start, $end),
            PlatformMetricConfig::STAYED_REVENUE => $this->stayedRevenue($config, $start, $end),
            PlatformMetricConfig::OBJECTS_BY_OCCUPANCY => $this->objectsByOccupancy($config, $start, $end),
            // A whole stay belongs to the period of its date_field (check-in based ADR).
            PlatformMetricConfig::BOOKING_NIGHTS => $this->bookings($config, $start, $end, 'COALESCE(SUM(GREATEST(0, DATEDIFF(DATE(b.departure_date), DATE(b.arrival_date)))), 0)'),
            default => throw new \LogicException(\sprintf('Calculator "%s" is not implemented.', $config->calculator)),
        };
    }

    public function hasSourceData(object $config): bool
    {
        $byListings = \in_array($this->config($config)->calculator, [PlatformMetricConfig::AVAILABLE_NIGHTS, PlatformMetricConfig::OBJECTS_BY_OCCUPANCY], true);
        $table = $byListings ? 'raw_platform_accommodation' : 'raw_platform_booking';

        return false !== $this->connection->fetchOne(\sprintf('SELECT 1 FROM %s LIMIT 1', $table));
    }

    /**
     * Bookings whose date_field falls into the period. Only EUR (or unknown currency) amounts count.
     */
    private function bookings(PlatformMetricConfig $config, \DateTimeImmutable $start, \DateTimeImmutable $end, string $aggregate): Number
    {
        // date_field is whitelisted by PlatformMetricConfig.
        [$filters, $params, $types] = $this->bookingFilters($config, $start);

        $sql = \sprintf(
            'SELECT %1$s FROM %4$s b WHERE b.%2$s >= :start AND b.%2$s < :end AND (b.currency IS NULL OR b.currency = \'EUR\') %3$s',
            $aggregate,
            $config->dateField,
            $filters,
            $config->daySnapshot ? self::DAY_SNAPSHOT_SOURCE : 'raw_platform_booking',
        );

        return $this->number($sql, [...$params, 'start' => $this->day($start), 'end' => $this->day($end)], $types);
    }

    /**
     * Nights of stay inside the period: a night belongs to the date it starts on.
     * sellable_only keeps the nights inside the sales window of the accommodation (the capacity of available_nights).
     */
    private function bookedNights(PlatformMetricConfig $config, \DateTimeImmutable $start, \DateTimeImmutable $end): Number
    {
        [$filters, $params, $types] = $this->bookingFilters($config, $start);
        $period = ['start' => $this->day($start), 'end' => $this->day($end)];

        if ($config->sellableOnly) {
            $sql = 'SELECT COALESCE(SUM(GREATEST(0, DATEDIFF(
                        LEAST(DATE(b.departure_date), COALESCE(w.sell_to, :end), :end),
                        GREATEST(DATE(b.arrival_date), w.sell_from, :start)
                    ))), 0)
                    FROM raw_platform_booking b
                    JOIN '.self::SALES_WINDOW.' w ON w.id = b.accommodation_id
                    WHERE b.arrival_date < :end AND b.departure_date > :start '.$filters;
            [$windowParams, $windowTypes] = $this->salesWindowParameters();

            return $this->number($sql, [...$params, ...$windowParams, ...$period], [...$types, ...$windowTypes]);
        }

        $sql = 'SELECT COALESCE(SUM(GREATEST(0, DATEDIFF(LEAST(DATE(b.departure_date), :end), GREATEST(DATE(b.arrival_date), :start)))), 0)
                FROM raw_platform_booking b
                WHERE b.arrival_date < :end AND b.departure_date > :start '.$filters;

        return $this->number($sql, [...$params, ...$period], $types);
    }

    /**
     * Sellable nights: every accommodation counts from its sales activation date (inclusive)
     * to its deactivation date (exclusive), see SALES_WINDOW.
     */
    private function availableNights(\DateTimeImmutable $start, \DateTimeImmutable $end): Number
    {
        $sql = 'SELECT COALESCE(SUM(GREATEST(0, DATEDIFF(LEAST(COALESCE(w.sell_to, :end), :end), GREATEST(w.sell_from, :start)))), 0)
                FROM '.self::SALES_WINDOW.' w';
        [$params, $types] = $this->salesWindowParameters();

        return $this->number($sql, [...$params, 'start' => $this->day($start), 'end' => $this->day($end)], $types);
    }

    /**
     * @return array{string, array<string, mixed>, array<string, ArrayParameterType>}
     */
    private function bookingFilters(PlatformMetricConfig $config, \DateTimeImmutable $periodStart, bool $withCancelledStays = false): array
    {
        // Cancelled stays: cancelled after the arrival day and before the departure day (see stayedRevenue).
        $sql = $withCancelledStays
            ? ' AND (b.status IN (:statuses) OR (b.status = \'CANCELLED\' AND DATE(b.cancelled_at) > DATE(b.arrival_date) AND DATE(b.cancelled_at) < DATE(b.departure_date)))'
            : ' AND b.status IN (:statuses)';
        $sql .= self::NOT_EXCLUDED;
        $params = ['statuses' => $config->statuses];
        $types = ['statuses' => ArrayParameterType::STRING];

        if ([] !== $config->portals) {
            $sql .= ' AND b.portal IN (:portals)';
            $params['portals'] = $config->portals;
            $types['portals'] = ArrayParameterType::STRING;
        }

        if ([] !== $config->excludePortals) {
            $sql .= ' AND (b.portal IS NULL OR b.portal NOT IN (:exclude_portals))';
            $params['exclude_portals'] = $config->excludePortals;
            $types['exclude_portals'] = ArrayParameterType::STRING;
        }

        if ([] !== $config->accommodationTypes) {
            $sql .= ' AND b.accommodation_id IN (SELECT a.id FROM raw_platform_accommodation a WHERE a.type IN (:accommodation_types))';
            $params['accommodation_types'] = $config->accommodationTypes;
            $types['accommodation_types'] = ArrayParameterType::STRING;
        }

        if (null !== $config->minNights) {
            $sql .= ' AND DATEDIFF(DATE(b.departure_date), DATE(b.arrival_date)) >= :min_nights';
            $params['min_nights'] = $config->minNights;
        }

        if (null !== $config->arrivalMonthOffset) {
            $month = $periodStart->modify('first day of this month')->modify(\sprintf('+%d months', $config->arrivalMonthOffset));
            $sql .= ' AND b.arrival_date >= :arrival_month_start AND b.arrival_date < :arrival_month_end';
            $params['arrival_month_start'] = $this->day($month);
            $params['arrival_month_end'] = $this->day($month->modify('+1 month'));
        }

        return [$sql, $params, $types];
    }

    /**
     * @param array<string, mixed>              $params
     * @param array<string, ArrayParameterType> $types
     */
    private function number(string $sql, array $params, array $types): Number
    {
        $value = $this->connection->fetchOne($sql, $params, $types);

        return new Number(is_numeric($value) ? (string) $value : '0');
    }

    private function day(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }

    public function sourceType(): MetricSourceType
    {
        return MetricSourceType::PlatformApi;
    }

    public function parseConfig(?array $config): ?PlatformMetricConfig
    {
        return PlatformMetricConfig::fromArray($config);
    }

    private function config(object $config): PlatformMetricConfig
    {
        return $config instanceof PlatformMetricConfig ? $config : throw new \LogicException(\sprintf('Expected %s, got %s.', PlatformMetricConfig::class, $config::class));
    }

    /**
     * Revenue of the nights stayed in the period («прожили», Marta's PriceLabs figure): every booking's amount is
     * spread evenly over its nights and the nights inside the period count (a night belongs to the date it starts
     * on). Multiplying before dividing keeps a whole stay exact. Only EUR (or unknown currency) amounts count.
     *
     * A booking cancelled during the stay keeps the nights before its cancellation day (Marta, 10.11.2026: the guest
     * stayed them); the night of the cancellation day and later ones do not count. Bookings cancelled before arrival
     * or only after departure (no-shows marked afterwards, edits after the cancellation) do not count: compared with
     * Marta's sheets this rule is the closest (10.11.2026).
     */
    private function stayedRevenue(PlatformMetricConfig $config, \DateTimeImmutable $start, \DateTimeImmutable $end): Number
    {
        [$filters, $params, $types] = $this->bookingFilters($config, $start, withCancelledStays: true);

        // amount_field is whitelisted by PlatformMetricConfig.
        $sql = \sprintf(
            'SELECT COALESCE(ROUND(SUM(
                    COALESCE(b.%s, 0) * GREATEST(0, DATEDIFF(
                        LEAST(IF(b.status = \'CANCELLED\', LEAST(DATE(b.departure_date), DATE(b.cancelled_at)), DATE(b.departure_date)), :end),
                        GREATEST(DATE(b.arrival_date), :start)
                    ))
                    / DATEDIFF(DATE(b.departure_date), DATE(b.arrival_date))
                ), 2), 0)
             FROM raw_platform_booking b
             WHERE b.arrival_date < :end AND b.departure_date > :start
               AND DATE(b.departure_date) > DATE(b.arrival_date)
               AND (b.currency IS NULL OR b.currency = \'EUR\') %s',
            $config->amountField,
            $filters,
        );

        return $this->number($sql, [...$params, 'start' => $this->day($start), 'end' => $this->day($end)], $types);
    }

    /**
     * @return array{array<string, mixed>, array<string, ArrayParameterType>}
     */
    private function salesWindowParameters(): array
    {
        return [
            ['not_guest' => self::NOT_GUEST_STATUSES, 'inactive' => self::INACTIVE_GUEST_STATUSES],
            ['not_guest' => ArrayParameterType::STRING, 'inactive' => ArrayParameterType::STRING],
        ];
    }

    public function isPointInTime(object $config): bool
    {
        return null !== $this->config($config)->forwardDays;
    }

    /**
     * Listings on sale in the period by their own occupancy in it (Marta's traffic light): taken nights (the
     * bookings the config selects, usually guests and owners) over the listing's sellable nights of the period.
     * green: threshold_pct or more; yellow: above zero but below it; red: no night taken; all: every listing.
     */
    private function objectsByOccupancy(PlatformMetricConfig $config, \DateTimeImmutable $start, \DateTimeImmutable $end): Number
    {
        [$filters, $params, $types] = $this->bookingFilters($config, $start);
        [$windowParams, $windowTypes] = $this->salesWindowParameters();

        $band = match ($config->band) {
            'green' => ' AND o.taken * 100 >= o.capacity * :threshold',
            'yellow' => ' AND o.taken > 0 AND o.taken * 100 < o.capacity * :threshold',
            'red' => ' AND o.taken = 0',
            default => '',
        };
        if ('' !== $band && 'red' !== $config->band) {
            $params['threshold'] = $config->thresholdPct;
        }

        // The bookings of the period are narrowed down first (a few hundred rows) and only then joined to the
        // listings: a subquery per listing scanned the whole bookings table for each of them.
        $sql = 'SELECT COUNT(*) FROM (
                    SELECT GREATEST(0, DATEDIFF(LEAST(COALESCE(w.sell_to, :end), :end), GREATEST(w.sell_from, :start))) AS capacity,
                           COALESCE(SUM(GREATEST(0, DATEDIFF(
                               LEAST(k.departure_day, COALESCE(w.sell_to, :end), :end),
                               GREATEST(k.arrival_day, w.sell_from, :start)
                           ))), 0) AS taken
                    FROM '.self::SALES_WINDOW.' w
                    LEFT JOIN (
                        SELECT b.accommodation_id, DATE(b.arrival_date) AS arrival_day, DATE(b.departure_date) AS departure_day
                        FROM raw_platform_booking b
                        WHERE b.arrival_date < :end AND b.departure_date > :start '.$filters.'
                    ) k ON k.accommodation_id = w.id
                    GROUP BY w.id, w.sell_from, w.sell_to
                ) o WHERE o.capacity > 0'.$band;

        return $this->number(
            $sql,
            [...$params, ...$windowParams, 'start' => $this->day($start), 'end' => $this->day($end)],
            [...$types, ...$windowTypes],
        );
    }
}
