<?php

declare(strict_types=1);

namespace App\DTO\Sprint;

use App\Entity\Action\RecurringTask;

final readonly class RecurringTaskDto
{
    public function __construct(
        public int $id,
        public string $title,
        public string $periodicity,
        public bool $isActive,
    ) {
    }

    public static function fromEntity(RecurringTask $task): self
    {
        return new self((int) $task->getId(), $task->getTitle(), $task->getPeriodicity()->value, $task->isActive());
    }
}
