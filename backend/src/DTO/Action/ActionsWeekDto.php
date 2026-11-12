<?php

declare(strict_types=1);

namespace App\DTO\Action;

use App\DTO\UserRefDto;

final readonly class ActionsWeekDto
{
    /**
     * @param list<BaseFunctionDto> $baseFunctions
     * @param list<RoadmapItemDto>  $roadmap
     * @param list<MissedItemDto>   $missed
     * @param list<MetricRefDto>    $metrics       metrics of the unit, for linking actions
     * @param list<UserRefDto>      $people        possible owners (members of the unit's branch)
     */
    public function __construct(
        public int $orgUnitId,
        public string $weekStart,
        public string $weekEnd,
        public string $monthStart,
        public ?string $prevWeekStart,
        public string $nextWeekStart,
        public bool $canManage,
        public ExecutionRateDto $weekExecution,
        public ExecutionRateDto $monthExecution,
        public array $baseFunctions,
        public array $roadmap,
        public array $missed,
        public array $metrics,
        public array $people,
    ) {
    }
}
