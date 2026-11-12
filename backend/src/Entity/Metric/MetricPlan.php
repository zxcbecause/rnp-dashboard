<?php

declare(strict_types=1);

namespace App\Entity\Metric;

use App\Entity\Auth\User;
use App\Enum\PeriodType;
use App\Repository\Metric\MetricPlanRepository;
use BcMath\Number;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MetricPlanRepository::class)]
#[ORM\Table(name: 'metric_plan')]
#[ORM\UniqueConstraint(name: 'uniq_metric_plan_period', columns: ['metric_id', 'period_type', 'period_start'])]
class MetricPlan
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

    #[ORM\Column(type: Types::NUMBER, precision: 18, scale: 4)]
    private Number $value;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'set_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $setBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $setAt;

    public function __construct(
        Metric $metric,
        PeriodType $periodType,
        \DateTimeImmutable $periodStart,
        Number $value,
        ?User $setBy = null,
    ) {
        $this->metric = $metric;
        $this->periodType = $periodType;
        $this->periodStart = $periodStart->setTime(0, 0);
        $this->value = $value;
        $this->setBy = $setBy;
        $this->setAt = new \DateTimeImmutable();
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

    public function getValue(): Number
    {
        return $this->value;
    }

    public function getSetBy(): ?User
    {
        return $this->setBy;
    }

    public function getSetAt(): \DateTimeImmutable
    {
        return $this->setAt;
    }

    public function update(Number $value, ?User $setBy): void
    {
        $this->value = $value;
        $this->setBy = $setBy;
        $this->setAt = new \DateTimeImmutable();
    }
}
