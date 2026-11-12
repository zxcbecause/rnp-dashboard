<?php

declare(strict_types=1);

namespace App\Entity\Action;

use App\Entity\Auth\User;
use App\Entity\Metric\Metric;
use App\Enum\RoadmapStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Additional (non-regular) action for a metric. Every change is recorded as a RoadmapEvent.
 */
#[ORM\Entity]
#[ORM\Table(name: 'roadmap_item')]
#[ORM\Index(name: 'idx_roadmap_item_metric_status', columns: ['metric_id', 'status'])]
class RoadmapItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Metric::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Metric $metric;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(length: 16, enumType: RoadmapStatus::class)]
    private RoadmapStatus $status = RoadmapStatus::Planned;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $expectedEffect = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Metric $metric, string $title)
    {
        $this->metric = $metric;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMetric(): Metric
    {
        return $this->metric;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        $this->touch();

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;
        $this->touch();

        return $this;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;
        $this->touch();

        return $this;
    }

    public function getStatus(): RoadmapStatus
    {
        return $this->status;
    }

    public function setStatus(RoadmapStatus $status): static
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function getExpectedEffect(): ?string
    {
        return $this->expectedEffect;
    }

    public function setExpectedEffect(?string $expectedEffect): static
    {
        $this->expectedEffect = $expectedEffect;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
