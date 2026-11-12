<?php

declare(strict_types=1);

namespace App\Entity\Action;

use App\Entity\Auth\User;
use App\Enum\RoadmapEffect;
use App\Enum\RoadmapEventType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only history of a roadmap item: creation, status/field changes and the
 * "did it affect the metric" conclusion.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'roadmap_event')]
#[ORM\Index(name: 'idx_roadmap_event_item_created', columns: ['roadmap_item_id', 'created_at'])]
class RoadmapEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RoadmapItem::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RoadmapItem $roadmapItem;

    #[ORM\Column(length: 24, enumType: RoadmapEventType::class)]
    private RoadmapEventType $type;

    /** Changed field name for field_changed / status_changed events. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $field;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $oldValue;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $newValue;

    /** Conclusion for effect_assessed events. */
    #[ORM\Column(length: 16, nullable: true, enumType: RoadmapEffect::class)]
    private ?RoadmapEffect $effect;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actor;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        RoadmapItem $roadmapItem,
        RoadmapEventType $type,
        ?User $actor,
        ?string $field = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?RoadmapEffect $effect = null,
        ?string $note = null,
    ) {
        $this->roadmapItem = $roadmapItem;
        $this->type = $type;
        $this->actor = $actor;
        $this->field = $field;
        $this->oldValue = $oldValue;
        $this->newValue = $newValue;
        $this->effect = $effect;
        $this->note = $note;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoadmapItem(): RoadmapItem
    {
        return $this->roadmapItem;
    }

    public function getType(): RoadmapEventType
    {
        return $this->type;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    public function getOldValue(): ?string
    {
        return $this->oldValue;
    }

    public function getNewValue(): ?string
    {
        return $this->newValue;
    }

    public function getEffect(): ?RoadmapEffect
    {
        return $this->effect;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
