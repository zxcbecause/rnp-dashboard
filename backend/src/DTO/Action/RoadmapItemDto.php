<?php

declare(strict_types=1);

namespace App\DTO\Action;

use App\DTO\UserRefDto;
use App\Entity\Action\RoadmapEvent;
use App\Entity\Action\RoadmapItem;
use App\Enum\RoadmapStatus;

final readonly class RoadmapItemDto
{
    /**
     * @param list<RoadmapEventDto> $events newest first
     */
    public function __construct(
        public int $id,
        public MetricRefDto $metric,
        public string $title,
        public ?UserRefDto $owner,
        public ?string $dueDate,
        public string $status,
        public ?string $expectedEffect,
        /** latest "did it affect the metric" conclusion: affected | not_affected | null */
        public ?string $effect,
        public bool $isOverdue,
        public bool $canEdit,
        public bool $canChangeStatus,
        public array $events,
    ) {
    }

    /**
     * @param list<RoadmapEvent> $events newest first
     */
    public static function fromEntity(RoadmapItem $item, array $events, \DateTimeImmutable $today, bool $canEdit, bool $canChangeStatus): self
    {
        $effect = null;
        foreach ($events as $event) {
            if (null !== $event->getEffect()) {
                $effect = $event->getEffect()->value;
                break;
            }
        }

        $open = \in_array($item->getStatus(), [RoadmapStatus::Planned, RoadmapStatus::InProgress], true);

        return new self(
            (int) $item->getId(),
            new MetricRefDto((int) $item->getMetric()->getId(), $item->getMetric()->getName()),
            $item->getTitle(),
            UserRefDto::fromNullable($item->getOwner()),
            $item->getDueDate()?->format('Y-m-d'),
            $item->getStatus()->value,
            $item->getExpectedEffect(),
            $effect,
            $open && null !== $item->getDueDate() && $item->getDueDate() < $today,
            $canEdit,
            $canChangeStatus,
            array_map(RoadmapEventDto::fromEntity(...), $events),
        );
    }
}
