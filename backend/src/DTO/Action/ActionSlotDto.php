<?php

declare(strict_types=1);

namespace App\DTO\Action;

use App\DTO\UserRefDto;

/**
 * One check slot of a base function. state: done | not_done | missed | pending | future.
 */
final readonly class ActionSlotDto
{
    public function __construct(
        public string $periodStart,
        public string $due,
        public string $state,
        public ?bool $done,
        public ?string $note,
        public ?UserRefDto $checkedBy,
        public ?string $checkedAt,
    ) {
    }
}
