<?php

declare(strict_types=1);

namespace App\Entity\Action;

use App\Entity\Org\OrgUnit;
use App\Enum\Periodicity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'recurring_task')]
class RecurringTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrgUnit::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OrgUnit $orgUnit;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 16, enumType: Periodicity::class)]
    private Periodicity $periodicity;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    public function __construct(OrgUnit $orgUnit, string $title, Periodicity $periodicity)
    {
        $this->orgUnit = $orgUnit;
        $this->title = $title;
        $this->periodicity = $periodicity;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrgUnit(): OrgUnit
    {
        return $this->orgUnit;
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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }
}
