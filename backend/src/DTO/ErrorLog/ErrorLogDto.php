<?php

declare(strict_types=1);

namespace App\DTO\ErrorLog;

use App\DTO\UserRefDto;

final readonly class ErrorLogDto
{
    /**
     * @param list<ErrorEntryDto> $entries  newest first
     * @param array<string, int>  $byImpact impact type => count
     * @param list<UserRefDto>    $people   possible owners
     */
    public function __construct(
        public int $orgUnitId,
        public string $from,
        public string $to,
        public bool $canReport,
        public int $total,
        public int $open,
        public array $byImpact,
        /** Sum of money impact in EUR over the period */
        public string $moneyTotal,
        public array $entries,
        public array $people,
    ) {
    }
}
