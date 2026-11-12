<?php

declare(strict_types=1);

namespace App\DTO\Sprint;

use App\DTO\Action\MetricRefDto;
use App\Entity\Action\SprintTask;

final readonly class SprintTaskDto
{
    public function __construct(
        public int $id,
        public string $weekStart,
        public ?string $tag,
        public string $priority,
        public string $title,
        public ?MetricRefDto $metric,
        public int $progressPct,
        public ?string $resultNote,
        public ?string $link,
        public ?string $nextStep,
        /** Week the task was carried from, when it is a carried-over copy */
        public ?string $carriedFromWeek,
    ) {
    }

    public static function fromEntity(SprintTask $task): self
    {
        $metric = $task->getMetric();

        return new self(
            (int) $task->getId(),
            $task->getWeekStart()->format('Y-m-d'),
            $task->getTag(),
            $task->getPriority()->value,
            $task->getTitle(),
            null === $metric ? null : new MetricRefDto((int) $metric->getId(), $metric->getName()),
            $task->getProgressPct(),
            $task->getResultNote(),
            $task->getLink(),
            $task->getNextStep(),
            $task->getCarriedFrom()?->getWeekStart()->format('Y-m-d'),
        );
    }
}
