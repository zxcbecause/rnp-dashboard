<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class MetricViewItem
{
    public function __construct(
        #[Assert\Positive]
        public int $metricId = 0,
        public bool $visible = true,
    ) {
    }
}
