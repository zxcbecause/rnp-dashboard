<?php

declare(strict_types=1);

namespace App\Entity\Metric;

use App\Entity\Auth\User;
use App\Enum\PeriodType;
use App\Enum\ValueOrigin;
use App\Repository\Metric\MetricValueRepository;
use BcMath\Number;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fact value of a metric for one period. Zero is stored as 0, "no data" is the absence of a row.
 * Percentages are stored as fractions (0.45), money in EUR.
 */
#[ORM\Entity(repositoryClass: MetricValueRepository::class)]
#[ORM\Table(name: 'metric_value')]
#[ORM\UniqueConstraint(name: 'uniq_metric_value_period', columns: ['metric_id', 'period_type', 'period_start'])]
class MetricValue
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

    #[ORM\Column(length: 8, enumType: ValueOrigin::class)]
    private ValueOrigin $origin;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'entered_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $enteredBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $enteredAt;

    public function __construct(
        Metric $metric,
        PeriodType $periodType,
        \DateTimeImmutable $periodStart,
        Number $value,
        ValueOrigin $origin,
        ?User $enteredBy = null,
    ) {
        $this->metric = $metric;
        $this->periodType = $periodType;
        $this->periodStart = $periodStart->setTime(0, 0);
        $this->value = $value;
        $this->origin = $origin;
        $this->enteredBy = $enteredBy;
        $this->enteredAt = new \DateTimeImmutable();
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

    public function getOrigin(): ValueOrigin
    {
        return $this->origin;
    }

    public function getEnteredBy(): ?User
    {
        return $this->enteredBy;
    }

    public function getEnteredAt(): \DateTimeImmutable
    {
        return $this->enteredAt;
    }

    public function update(Number $value, ValueOrigin $origin, ?User $enteredBy): void
    {
        $this->value = $value;
        $this->origin = $origin;
        $this->enteredBy = $enteredBy;
        $this->enteredAt = new \DateTimeImmutable();
    }
}
