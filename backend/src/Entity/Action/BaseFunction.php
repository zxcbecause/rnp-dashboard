<?php

declare(strict_types=1);

namespace App\Entity\Action;

use App\Entity\Auth\User;
use App\Entity\Metric\Metric;
use App\Entity\Org\OrgUnit;
use App\Enum\Periodicity;
use Doctrine\ORM\Mapping as ORM;

/**
 * Regular action of an org unit, optionally tied to the metric it keeps growing.
 * Completion is tracked per period in BaseFunctionCheck (day / week segment / month / quarter).
 */
#[ORM\Entity]
#[ORM\Table(name: 'base_function')]
#[ORM\Index(name: 'idx_base_function_unit_active', columns: ['org_unit_id', 'is_active'])]
class BaseFunction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrgUnit::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OrgUnit $orgUnit;

    #[ORM\ManyToOne(targetEntity: Metric::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Metric $metric;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 16, enumType: Periodicity::class)]
    private Periodicity $periodicity;

    /** Deadline inside the period as written by the business, e.g. "до 20 числа", "в течение суток". */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dueText = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $ownerUser = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(options: ['default' => 0])]
    private int $sort = 0;

    public function __construct(OrgUnit $orgUnit, string $title, Periodicity $periodicity, ?Metric $metric = null)
    {
        $this->orgUnit = $orgUnit;
        $this->title = $title;
        $this->periodicity = $periodicity;
        $this->setMetric($metric);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrgUnit(): OrgUnit
    {
        return $this->orgUnit;
    }

    public function getMetric(): ?Metric
    {
        return $this->metric;
    }

    public function setMetric(?Metric $metric): static
    {
        if (null !== $metric && $metric->getOrgUnit() !== $this->orgUnit) {
            throw new \InvalidArgumentException('The metric must belong to the same org unit.');
        }
        $this->metric = $metric;

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

    public function getPeriodicity(): Periodicity
    {
        return $this->periodicity;
    }

    public function setPeriodicity(Periodicity $periodicity): static
    {
        $this->periodicity = $periodicity;

        return $this;
    }

    public function getDueText(): ?string
    {
        return $this->dueText;
    }

    public function setDueText(?string $dueText): static
    {
        $this->dueText = $dueText;

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
}
