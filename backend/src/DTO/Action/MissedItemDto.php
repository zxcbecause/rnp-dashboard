<?php

declare(strict_types=1);

namespace App\DTO\Action;

use App\DTO\UserRefDto;

final readonly class MissedItemDto
{
    public function __construct(
        /** base_function | roadmap */
        public string $kind,
        public int $id,
        public string $title,
        public string $periodStart,
        public string $due,
        public ?UserRefDto $owner,
    ) {
    }
}
