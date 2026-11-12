<?php

declare(strict_types=1);

namespace App\Entity\Metric;

use App\Entity\Auth\User;
use App\Entity\Org\OrgUnit;
use App\Enum\MetricDirection;
use App\Enum\MetricSourceType;
use App\Enum\MetricUnit;
use App\Enum\PeriodType;
use App\Enum\TimeAggregation;
use App\Enum\TreeAggregation;
use App\Repository\Metric\MetricRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Metric definition. Ratios (CPL, ADR, conversion, occupancy...) use time_agg = formula and are
 * never stored or averaged: they are recomputed from numerator/denominator on every level.
 */
#[ORM\Entity(repositoryClass: MetricRepository::class)]
#[ORM\Table(name: 'metric')]
#[ORM\UniqueConstraint(name: 'uniq_metric_code', columns: ['code'])]
#[ORM\Index(name: 'idx_metric_unit_sort', columns: ['org_unit_id', 'sort'])]
class Metric
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrgUnit::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private OrgUnit $orgUnit;

    /** Stable identifier, referenced from formulas of other metrics. */
    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 16, enumType: MetricUnit::class)]
    private MetricUnit $unit;

    #[ORM\Column(length: 16, enumType: MetricDirection::class)]
    private MetricDirection $direction = MetricDirection::HigherBetter;

    #[ORM\Column(length: 16, enumType: TimeAggregation::class)]
    private TimeAggregation $timeAgg = TimeAggregation::Sum;

    #[ORM\Column(length: 16, enumType: TreeAggregation::class)]
    private TreeAggregation $treeAgg = TreeAggregation::Sum;

    /** Expression over other metric codes, e.g. "revenue_bookings_sum / revenue_bookings_count". */
    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $formula = null;

    #[ORM\Column(length: 16, enumType: MetricSourceType::class)]
    private MetricSourceType $sourceType = MetricSourceType::Manual;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $sourceConfig = null;

    #[ORM\Column(length: 8, enumType: PeriodType::class)]
    private PeriodType $granularity = PeriodType::Week;

    /** Who enters the value. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'filler_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $fillerUser = null;

    /** Who knows how to move the metric. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $ownerUser = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(options: ['default' => 0])]
    private int $sort = 0;

    /** Show the average per day next to the period total in the RNP (additive weekly metrics, e.g. calls). */
    #[ORM\Column(options: ['default' => false])]
    private bool $showDailyAvg = false;

    // "Metric logic" card.

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $whatMeasured = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $formulaText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $sourceText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $periodText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $usedIn = null;

    /**
     * Plan computed per period when none is set by hand or imported, e.g. "cs_direct_base_share.plan * cs_incoming_sum":
     * a bare code is that metric's fact of the same period, "code.plan" its explicitly set plan.
     */
    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $planFormula = null;

    public function __construct(OrgUnit $orgUnit, string $code, string $name, MetricUnit $unit)
    {
        $this->orgUnit = $orgUnit;
        $this->code = $code;
        $this->name = $name;
        $this->unit = $unit;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrgUnit(): OrgUnit
    {
        return $this->orgUnit;
    }

    public function setOrgUnit(OrgUnit $orgUnit): static
    {
        $this->orgUnit = $orgUnit;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getUnit(): MetricUnit
    {
        return $this->unit;
    }

    public function setUnit(MetricUnit $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function getDirection(): MetricDirection
    {
        return $this->direction;
    }

    public function setDirection(MetricDirection $direction): static
    {
        $this->direction = $direction;

        return $this;
    }

    public function getTimeAgg(): TimeAggregation
    {
        return $this->timeAgg;
    }

    public function setTimeAgg(TimeAggregation $timeAgg): static
    {
        $this->timeAgg = $timeAgg;

        return $this;
    }

    public function getTreeAgg(): TreeAggregation
    {
        return $this->treeAgg;
    }

    public function setTreeAgg(TreeAggregation $treeAgg): static
    {
        $this->treeAgg = $treeAgg;

        return $this;
    }

    public function getFormula(): ?string
    {
        return $this->formula;
    }

    public function setFormula(?string $formula): static
    {
        $this->formula = $formula;

        return $this;
    }

    public function getSourceType(): MetricSourceType
    {
        return $this->sourceType;
    }

    public function setSourceType(MetricSourceType $sourceType): static
    {
        $this->sourceType = $sourceType;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSourceConfig(): ?array
    {
        return $this->sourceConfig;
    }

    /**
     * @param array<string, mixed>|null $sourceConfig
     */
    public function setSourceConfig(?array $sourceConfig): static
    {
        $this->sourceConfig = $sourceConfig;

        return $this;
    }

    public function getGranularity(): PeriodType
    {
        return $this->granularity;
    }

    public function setGranularity(PeriodType $granularity): static
    {
        $this->granularity = $granularity;

        return $this;
    }

    public function getFillerUser(): ?User
    {
        return $this->fillerUser;
    }

    public function setFillerUser(?User $fillerUser): static
    {
        $this->fillerUser = $fillerUser;

        return $this;
    }

    public function getOwnerUser(): ?User
    {
        return $this->ownerUser;
    }

    public function setOwnerUser(?User $ownerUser): static
    {
        $this->ownerUser = $ownerUser;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getSort(): int
    {
        return $this->sort;
    }

    public function setSort(int $sort): static
    {
        $this->sort = $sort;

        return $this;
    }

    public function getWhatMeasured(): ?string
    {
        return $this->whatMeasured;
    }

    public function setWhatMeasured(?string $whatMeasured): static
    {
        $this->whatMeasured = $whatMeasured;

        return $this;
    }

    public function getFormulaText(): ?string
    {
        return $this->formulaText;
    }

    public function setFormulaText(?string $formulaText): static
    {
        $this->formulaText = $formulaText;

        return $this;
    }

    public function getSourceText(): ?string
    {
        return $this->sourceText;
    }

    public function setSourceText(?string $sourceText): static
    {
        $this->sourceText = $sourceText;

        return $this;
    }

    public function getPeriodText(): ?string
    {
        return $this->periodText;
    }

    public function setPeriodText(?string $periodText): static
    {
        $this->periodText = $periodText;

        return $this;
    }

    public function getUsedIn(): ?string
    {
        return $this->usedIn;
    }

    public function setUsedIn(?string $usedIn): static
    {
        $this->usedIn = $usedIn;

        return $this;
    }

    public function isManual(): bool
    {
        return MetricSourceType::Manual === $this->sourceType;
    }

    public function getPlanFormula(): ?string
    {
        return $this->planFormula;
    }

    public function setPlanFormula(?string $planFormula): static
    {
        $this->planFormula = $planFormula;

        return $this;
    }

    public function isShowDailyAvg(): bool
    {
        return $this->showDailyAvg;
    }

    public function setShowDailyAvg(bool $showDailyAvg): static
    {
        $this->showDailyAvg = $showDailyAvg;

        return $this;
    }
}
