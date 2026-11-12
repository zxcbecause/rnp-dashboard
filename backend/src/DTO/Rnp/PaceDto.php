<?php

declare(strict_types=1);

namespace App\DTO\Rnp;

/**
 * Daily pace of an additive metric against its month plan: what it takes per day to finish the month on plan
 * (for lower-is-better metrics: how much per day is still allowed), next to the pace so far.
 */
final readonly class PaceDto
{
    public function __construct(
        /** month fact to date / elapsed days */
        public string $currentPerDay,
        /** (month plan − month fact) / remaining days, never below 0 (0 = plan already met / budget used up) */
        public string $requiredPerDay,
        public int $remainingDays,
        /** the month plan is already met (higher is better) or already exceeded (lower is better) */
        public bool $planReached,
    ) {
    }
}
