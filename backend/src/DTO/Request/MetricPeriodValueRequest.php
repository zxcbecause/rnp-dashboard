<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * PUT /api/metrics/{id}/facts and /plans. value = null removes the stored value.
 */
final readonly class MetricPeriodValueRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['week', 'month'])]
        public string $periodType = '',
        #[Assert\NotBlank]
        #[Assert\Date]
        public string $periodStart = '',
        /** Decimal as a string ("1234.50"); numbers are accepted for integers only. */
        public string|int|null $value = null,
    ) {
    }
}
