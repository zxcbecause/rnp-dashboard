<?php

declare(strict_types=1);

namespace App\DTO\Action;

use App\DTO\UserRefDto;

final readonly class BaseFunctionDto
{
    /**
     * @param list<ActionSlotDto> $slots
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $periodicity,
        public ?string $dueText,
        public ?MetricRefDto $metric,
        public ?UserRefDto $owner,
        public bool $isActive,
        public bool $canCheck,
        public array $slots,
    ) {
    }
}
