<?php

declare(strict_types=1);

namespace App\DTO\Calls;

final readonly class MissedCallDto
{
    public function __construct(
        /** Bitrix call activity id */
        public int $callId,
        /** Madrid local time, Y-m-d\TH:i:s */
        public string $startedAt,
        /** Bitrix user the call was routed to */
        public ?int $responsibleId,
        /** CRM card the call is attached to (lead / deal / contact / company), when the portal is known */
        public ?string $crmUrl,
        /** Whether an exclusion may still be requested (none yet, call inside the editable window) */
        public bool $canRequest,
        public ?CallExclusionDto $exclusion,
        /** false: the call was answered (listed only with ?all=true) */
        public bool $missed = true,
    ) {
    }
}
