<?php

declare(strict_types=1);

namespace App\Service\Metric\Calculation;

/**
 * Parsed and validated metric.source_config of a platform_api metric:
 *
 *   {"calculator": "bookings_sum", "date_field": "creation_date",
 *    "statuses": ["CONFIRMED", ...], "portals": [...], "exclude_portals": [...],
 *    "amount_field": "rental_price"}
 *
 * amount_field (bookings_sum, stayed_revenue): "total" (default, the full booking price) or "rental_price"
 * (rent only, VAT included, without cleaning/extras/tourist tax — what CS counts in «Метрики РНП»).
 *
 *   {"calculator": "stayed_revenue", "amount_field": "rental_price"}
 *       revenue of the nights stayed in the period («Сумма бронирований (прожили)»): each booking's amount is
 *       spread evenly over its nights, a night belongs to the date it starts on (as booked_nights); a booking
 *       cancelled during the stay keeps the nights before its cancellation day.
 *
 *   {"calculator": "booking_nights", "date_field": "arrival_date", "accommodation_types": ["APARTMENT", "STUDIO"]}
 *       all nights of the bookings whose date_field falls into the period (the whole stay, wherever it ends):
 *       the denominator of check-in based ADR.
 *
 * accommodation_types (any booking calculator): only bookings of accommodations of these platform types.
 * min_nights (any booking calculator): only stays of at least this many nights («долгосрочные» = 14, Marta).
 * arrival_month_offset (any booking calculator): only bookings arriving in the month of the period plus this offset
 *   (0 = the same month, 1 = the next one; «заезд в ноябре / декабре»). Periods never cross a month boundary.
 * date_field "cancelled_at": the day the dashboard first saw the booking cancelled (with statuses ["CANCELLED"]).
 * day_snapshot (bookings_sum / bookings_count by creation_date): bookings of a day as they were at 23:55 Madrid that
 *   day (booking_day_snapshot, like Gleb's Avantio export for manager KPI): a booking cancelled or changed later
 *   keeps its evening state. Days without a snapshot (before 10.11.2026, or when it was not taken) use the current
 *   state, as without the option.
 * sellable_only (booked_nights): only the nights inside the sales window of the accommodation, i.e. the nights
 *   available_nights counts as capacity. For owner stays and calendar blocks (statuses ["OWNER"] / ["UNAVAILABLE"]),
 *   which also sit on listings that are off sale: Marta's «Загрузка общая» and «свободные ночи» divide by capacity.
 * forward_days (booked_nights, available_nights): the nights of the N days AFTER the last day of the period instead
 *   of the period itself — «Загрузка на следующие 5 дней» (Marta): a forecast as of that day. Such a metric is a
 *   point-in-time one: only the running period is computed, finished periods keep the value they had.
 *
 *   {"calculator": "objects_by_occupancy", "band": "red", "threshold_pct": 60, "statuses": [..., "OWNER"]}
 *       number of listings on sale in the period by their own occupancy in it (Marta's traffic light): "green" —
 *       threshold_pct or more of their sellable nights are taken, "yellow" — some but fewer, "red" — none,
 *       "all" — every listing on sale.
 *
 * Every SQL identifier comes from a whitelist here, never from the config string itself.
 */
final readonly class PlatformMetricConfig
{
    public const string BOOKINGS_SUM = 'bookings_sum';
    public const string BOOKINGS_COUNT = 'bookings_count';
    public const string BOOKED_NIGHTS = 'booked_nights';
    public const string AVAILABLE_NIGHTS = 'available_nights';
    public const string STAYED_REVENUE = 'stayed_revenue';
    public const string BOOKING_NIGHTS = 'booking_nights';
    public const string OBJECTS_BY_OCCUPANCY = 'objects_by_occupancy';

    public const array BANDS = ['green', 'yellow', 'red', 'all'];

    private const array CALCULATORS = [self::BOOKINGS_SUM, self::BOOKINGS_COUNT, self::BOOKED_NIGHTS, self::AVAILABLE_NIGHTS, self::STAYED_REVENUE, self::BOOKING_NIGHTS, self::OBJECTS_BY_OCCUPANCY];

    private const array DATE_FIELDS = ['creation_date', 'arrival_date', 'departure_date', 'last_modification', 'cancelled_at'];

    private const array AMOUNT_FIELDS = ['total', 'rental_price'];

    /**
     * Booking statuses that count as a sale by default. Pending business confirmation:
     * OWNER/UNAVAILABLE are blocks, CANCELLED is lost, REQUESTED/UNDER_REQUEST/CONFLICTED/WARRANTY unclear.
     */
    public const array DEFAULT_STATUSES = ['CONFIRMED', 'UNPAID', 'INVOICED', 'PAID', 'IN_PROGRESS'];

    /**
     * @param list<string> $statuses
     * @param list<string> $portals            empty = any portal
     * @param list<string> $excludePortals
     * @param list<string> $accommodationTypes empty = any type
     */
    private function __construct(
        public string $calculator,
        public string $dateField,
        public array $statuses,
        public array $portals,
        public array $excludePortals,
        public string $amountField = 'total',
        public array $accommodationTypes = [],
        public ?int $minNights = null,
        public ?int $arrivalMonthOffset = null,
        public bool $daySnapshot = false,
        public bool $sellableOnly = false,
        public ?int $forwardDays = null,
        public ?string $band = null,
        public int $thresholdPct = 60,
    ) {
    }

    /**
     * @param array<string, mixed>|null $config
     *
     * @return self|null null when the metric is not computed from platform data
     */
    public static function fromArray(?array $config): ?self
    {
        $calculator = $config['calculator'] ?? null;
        if (null === $config || null === $calculator) {
            return null;
        }

        if (!\in_array($calculator, self::CALCULATORS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown calculator "%s".', \is_scalar($calculator) ? $calculator : get_debug_type($calculator)));
        }

        $dateField = $config['date_field'] ?? 'creation_date';
        if (!\in_array($dateField, self::DATE_FIELDS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unsupported date_field "%s".', \is_scalar($dateField) ? $dateField : get_debug_type($dateField)));
        }

        $amountField = $config['amount_field'] ?? 'total';
        if (!\in_array($amountField, self::AMOUNT_FIELDS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unsupported amount_field "%s".', \is_scalar($amountField) ? $amountField : get_debug_type($amountField)));
        }

        $daySnapshot = $config['day_snapshot'] ?? false;
        if (!\is_bool($daySnapshot)) {
            throw new \InvalidArgumentException('"day_snapshot" must be true or false.');
        }
        if ($daySnapshot && (!\in_array($calculator, [self::BOOKINGS_SUM, self::BOOKINGS_COUNT], true) || 'creation_date' !== $dateField)) {
            throw new \InvalidArgumentException('"day_snapshot" works only for bookings_sum / bookings_count by creation_date.');
        }

        $sellableOnly = $config['sellable_only'] ?? false;
        if (!\is_bool($sellableOnly)) {
            throw new \InvalidArgumentException('"sellable_only" must be true or false.');
        }
        if ($sellableOnly && self::BOOKED_NIGHTS !== $calculator) {
            throw new \InvalidArgumentException('"sellable_only" works only for booked_nights.');
        }

        $forwardDays = self::int($config, 'forward_days', 1, 31);
        if (null !== $forwardDays && !\in_array($calculator, [self::BOOKED_NIGHTS, self::AVAILABLE_NIGHTS], true)) {
            throw new \InvalidArgumentException('"forward_days" works only for booked_nights and available_nights.');
        }

        $band = $config['band'] ?? null;
        if (self::OBJECTS_BY_OCCUPANCY === $calculator) {
            if (!\in_array($band, self::BANDS, true)) {
                throw new \InvalidArgumentException('"band" must be green, yellow, red or all.');
            }
        } elseif (null !== $band || \array_key_exists('threshold_pct', $config)) {
            throw new \InvalidArgumentException('"band" and "threshold_pct" work only for objects_by_occupancy.');
        }

        return new self(
            $calculator,
            $dateField,
            self::strings($config, 'statuses') ?? self::DEFAULT_STATUSES,
            self::strings($config, 'portals') ?? [],
            self::strings($config, 'exclude_portals') ?? [],
            $amountField,
            self::strings($config, 'accommodation_types') ?? [],
            self::int($config, 'min_nights', 1, 365),
            self::int($config, 'arrival_month_offset', 0, 24),
            $daySnapshot,
            $sellableOnly,
            $forwardDays,
            \is_string($band) ? $band : null,
            self::int($config, 'threshold_pct', 1, 100) ?? 60,
        );
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>|null
     */
    private static function strings(array $config, string $key): ?array
    {
        if (!\array_key_exists($key, $config)) {
            return null;
        }

        $value = $config[$key];
        if (!\is_array($value) || [] !== array_filter($value, static fn ($v): bool => !\is_string($v) || '' === $v)) {
            throw new \InvalidArgumentException(\sprintf('"%s" must be a list of non-empty strings.', $key));
        }

        /* @var list<string> */
        return array_values($value);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function int(array $config, string $key, int $min, int $max): ?int
    {
        if (!\array_key_exists($key, $config)) {
            return null;
        }

        $value = $config[$key];
        if (!\is_int($value) || $value < $min || $value > $max) {
            throw new \InvalidArgumentException(\sprintf('"%s" must be an integer from %d to %d.', $key, $min, $max));
        }

        return $value;
    }
}
