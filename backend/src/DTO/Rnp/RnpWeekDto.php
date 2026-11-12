<?php

declare(strict_types=1);

namespace App\DTO\Rnp;

use App\DTO\OrgUnitDto;

final readonly class RnpWeekDto
{
    /**
     * @param list<RnpRowDto> $rows
     */
    public function __construct(
        public OrgUnitDto $orgUnit,
        public int $weekIndex,
        public string $weekStart,
        public string $weekEnd,
        public string $monthStart,
        public ?string $prevWeekStart,
        public string $nextWeekStart,
        public bool $isCurrentWeek,
        public array $rows,
        /** Metrics of the unit hidden in the current user's view */
        public int $hiddenCount = 0,
    ) {
    }
}
