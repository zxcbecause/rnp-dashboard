<?php

declare(strict_types=1);

namespace App\DTO\Calls;

use App\DTO\UserRefDto;
use App\Entity\Journal\CallExclusion;

final readonly class CallExclusionDto
{
    public function __construct(
        public int $id,
        public int $callId,
        /** Madrid local time, Y-m-d\TH:i:s */
        public string $callStartedAt,
        public ?int $responsibleId,
        public string $reason,
        /** pending | approved | rejected */
        public string $status,
        public ?UserRefDto $requestedBy,
        public string $requestedAt,
        public ?UserRefDto $decidedBy,
        public ?string $decidedAt,
        public ?string $decisionNote,
        /** Whether the current user may withdraw the pending request */
        public bool $canWithdraw,
        /** technical | spam | test | own | other */
        public string $category,
        /** A screenshot is attached: GET /api/call-exclusions/{id}/image */
        public bool $hasImage = false,
    ) {
    }

    public static function fromEntity(CallExclusion $exclusion, bool $canWithdraw, bool $hasImage = false): self
    {
        return new self(
            (int) $exclusion->getId(),
            $exclusion->getCallId(),
            $exclusion->getCallStartedAt()->format('Y-m-d\TH:i:s'),
            $exclusion->getResponsibleId(),
            $exclusion->getReason(),
            $exclusion->getStatus()->value,
            UserRefDto::fromNullable($exclusion->getRequestedBy()),
            $exclusion->getRequestedAt()->format(\DATE_ATOM),
            UserRefDto::fromNullable($exclusion->getDecidedBy()),
            $exclusion->getDecidedAt()?->format(\DATE_ATOM),
            $exclusion->getDecisionNote(),
            $canWithdraw,
            $exclusion->getCategory()->value,
            $hasImage,
        );
    }
}
