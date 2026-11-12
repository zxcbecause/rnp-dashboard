<?php

declare(strict_types=1);

namespace App\DTO\Calls;

use App\DTO\OrgUnitDto;

/**
 * GET /api/org-units/{id}/missed-calls: missed incoming calls of the period with their exclusion requests,
 * plus every pending request of the unit (older ones too) for the moderator.
 */
final readonly class MissedCallsDto
{
    /**
     * @param list<MissedCallDto>    $calls   newest first
     * @param list<CallExclusionDto> $pending oldest first
     */
    public function __construct(
        public OrgUnitDto $orgUnit,
        public string $from,
        public string $to,
        /** Requests are accepted for calls from this day on (start of the previous month) */
        public string $editableFrom,
        public bool $canRequest,
        public bool $canDecide,
        public array $calls,
        public array $pending,
    ) {
    }
}
