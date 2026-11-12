<?php

declare(strict_types=1);

namespace App\Entity\Action;

use App\Entity\Metric\Metric;
use App\Entity\Org\OrgUnit;
use App\Enum\SprintPriority;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Weekly task of an org unit. An unfinished task can be carried to the next week (carried_from).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sprint_task')]
#[ORM\Index(name: 'idx_sprint_task_unit_week', columns: ['org_unit_id', 'week_start'])]
class SprintTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrgUnit::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OrgUnit $orgUnit;

    /** Monday of the sprint week. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $weekStart;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tag = null;

    #[ORM\Column(length: 2, enumType: SprintPriority::class)]
    private SprintPriority $priority = SprintPriority::P3;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\ManyToOne(targetEntity: Metric::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Metric $metric = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $progressPct = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resultNote = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $link = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $nextStep = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'carried_from_id', nullable: true, onDelete: 'SET NULL')]
    private ?SprintTask $carriedFrom = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(OrgUnit $orgUnit, \DateTimeImmutable $weekStart, string $title)
    {
        if ('1' !== $weekStart->format('N')) {
            throw new \InvalidArgumentException('Sprint week must start on Monday.');
        }

        $this->orgUnit = $orgUnit;
        $this->weekStart = $weekStart->setTime(0, 0);
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrgUnit(): OrgUnit
    {
        return $this->orgUnit;
    }

    public function getWeekStart(): \DateTimeImmutable
    {
        return $this->weekStart;
    }

    public function getTag(): ?string
    {
        return $this->tag;
    }

    public function setTag(?string $tag): static
    {
        $this->tag = $tag;

        return $this;
    }

    public function getPriority(): SprintPriority
    {
        return $this->priority;
    }

    public function setPriority(SprintPriority $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getMetric(): ?Metric
    {
        return $this->metric;
    }

    public function setMetric(?Metric $metric): static
    {
        $this->metric = $metric;

        return $this;
    }

    public function getProgressPct(): int
    {
        return $this->progressPct;
    }

    public function setProgressPct(int $progressPct): static
    {
        if ($progressPct < 0 || $progressPct > 100) {
            throw new \InvalidArgumentException('Progress must be between 0 and 100.');
        }

        $this->progressPct = $progressPct;

        return $this;
    }

    public function getResultNote(): ?string
    {
        return $this->resultNote;
    }

    public function setResultNote(?string $resultNote): static
    {
        $this->resultNote = $resultNote;

        return $this;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function setLink(?string $link): static
    {
        $this->link = $link;

        return $this;
    }

    public function getNextStep(): ?string
    {
        return $this->nextStep;
    }

    public function setNextStep(?string $nextStep): static
    {
        $this->nextStep = $nextStep;

        return $this;
    }

    public function getCarriedFrom(): ?self
    {
        return $this->carriedFrom;
    }

    public function setCarriedFrom(?self $carriedFrom): static
    {
        $this->carriedFrom = $carriedFrom;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
