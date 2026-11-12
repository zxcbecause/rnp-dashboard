<?php

declare(strict_types=1);

namespace App\DTO\Rnp;

/**
 * Average per day of an additive metric, shown next to its totals (Gleb, 11.11.2026: calls as sums plus the
 * average per day). Decimal strings with one digit after the point at most.
 */
final readonly class DailyAverageDto
{
    public function __construct(
        /** week fact / days of the week segment (a running week of a computed metric: days up to today) */
        public ?string $week,
        /** month fact to date / days it covers */
        public ?string $month,
    ) {
    }
}
