<?php

declare(strict_types=1);

namespace App\DTO\Sprint;

use App\DTO\Action\MetricRefDto;

final readonly class SprintWeekDto
{
    /**
     * @param list<SprintTaskDto>    $tasks     by priority, then creation
     * @param list<RecurringTaskDto> $recurring all recurring tasks of the unit (inactive included)
     * @param list<MetricRefDto>     $metrics
     */
    public function __construct(
        public int $orgUnitId,
        /** Monday */
        public string $weekStart,
        /** Sunday */
        public string $weekEnd,
        public string $prevWeekStart,
        public string $nextWeekStart,
        public bool $canEdit,
        public bool $canManageRecurring,
        public int $doneCount,
        /** Average progress of the week's tasks, 0–100; null without tasks */
        public ?int $avgProgressPct,
        /** Unfinished tasks of this week not yet carried to the next one */
        public int $carryableCount,
        public array $tasks,
        public array $recurring,
        public array $metrics,
    ) {
    }
}
