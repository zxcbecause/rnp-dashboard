<?php

declare(strict_types=1);

namespace App\DTO\Action;

use App\DTO\UserRefDto;
use App\Entity\Action\RoadmapEvent;

final readonly class RoadmapEventDto
{
    public function __construct(
        public int $id,
        public string $type,
        public ?string $field,
        public ?string $oldValue,
        public ?string $newValue,
        public ?string $effect,
        public ?string $note,
        public ?UserRefDto $actor,
        public string $createdAt,
    ) {
    }

    public static function fromEntity(RoadmapEvent $event): self
    {
        return new self(
            (int) $event->getId(),
            $event->getType()->value,
            $event->getField(),
            $event->getOldValue(),
            $event->getNewValue(),
            $event->getEffect()?->value,
            $event->getNote(),
            UserRefDto::fromNullable($event->getActor()),
            $event->getCreatedAt()->format(\DATE_ATOM),
        );
    }
}
