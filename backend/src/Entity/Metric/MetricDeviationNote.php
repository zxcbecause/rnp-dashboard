<?php

declare(strict_types=1);

namespace App\Entity\Metric;

use App\Entity\Auth\User;
use App\Enum\PeriodType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Why a metric deviated from the plan in a period and what is being done about it — the RNP "разбор отклонения",
 * written by the head (or drafted by AI and edited). One note per metric and period.
 */
#[ORM\Entity]
#[ORM\Table(name: 'metric_deviation_note')]
#[ORM\UniqueConstraint(name: 'uniq_metric_deviation_note_period', columns: ['metric_id', 'period_type', 'period_start'])]
class MetricDeviationNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Metric::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Metric $metric;

    #[ORM\Column(length: 8, enumType: PeriodType::class)]
    private PeriodType $periodType;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    /** What is being done; may be empty when the cause is outside the team's control. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $action;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Metric $metric, PeriodType $periodType, \DateTimeImmutable $periodStart, string $reason, ?string $action, ?User $author)
    {
        $this->metric = $metric;
        $this->periodType = $periodType;
        $this->periodStart = $periodStart->setTime(0, 0);
        $this->reason = $reason;
        $this->action = $action;
        $this->author = $author;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMetric(): Metric
    {
        return $this->metric;
    }

    public function getPeriodType(): PeriodType
    {
        return $this->periodType;
    }

    public function getPeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(string $reason, ?string $action, ?User $author): self
    {
        $this->reason = $reason;
        $this->action = $action;
        $this->author = $author;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
