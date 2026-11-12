<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/metrics/{id}/ai/anomaly.
 */
final readonly class AnomalyRequest
{
    public function __construct(
        /** "week": why the week segment is red; "forecast": why the month run rate is behind the plan */
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['week', 'forecast'])]
        public string $kind = '',
        /** Any day of the week (week) or of the month (forecast, the shown week's day) */
        #[Assert\NotBlank]
        #[Assert\Date]
        public string $date = '',
    ) {
    }
}
