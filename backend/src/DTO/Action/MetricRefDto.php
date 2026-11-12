<?php

declare(strict_types=1);

namespace App\DTO\Action;

final readonly class MetricRefDto
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}
