<?php

declare(strict_types=1);

namespace App\DTO\Action;

final readonly class ExecutionRateDto
{
    public function __construct(
        public int $planned,
        public int $done,
        /** done / planned × 100, one decimal; null when nothing was due */
        public ?string $pct,
        public int $baseFunctionsPlanned,
        public int $baseFunctionsDone,
        public int $roadmapPlanned,
        public int $roadmapDone,
        public int $sprintPlanned = 0,
        public int $sprintDone = 0,
    ) {
    }
}
