<?php

declare(strict_types=1);

namespace App\Entity\Journal;

use App\Entity\Auth\User;
use App\Entity\Org\OrgUnit;
use App\Enum\ErrorImpactType;
use App\Enum\ErrorStatus;
use BcMath\Number;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Significant mistake with consequences (money, blocked work, client impact).
 */
#[ORM\Entity]
#[ORM\Table(name: 'error_entry')]
#[ORM\Index(name: 'idx_error_entry_unit_date', columns: ['org_unit_id', 'occurred_on'])]
class ErrorEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrgUnit::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OrgUnit $orgUnit;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $occurredOn;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reporter_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $reporter;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(length: 16, enumType: ErrorImpactType::class)]
    private ErrorImpactType $impactType;

    /** Money impact in EUR, when known. */
    #[ORM\Column(type: Types::NUMBER, precision: 14, scale: 2, nullable: true)]
    private ?Number $impactAmount = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $cause = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $prevention = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    #[ORM\Column(length: 16, enumType: ErrorStatus::class)]
    private ErrorStatus $status = ErrorStatus::Open;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        OrgUnit $orgUnit,
        \DateTimeImmutable $occurredOn,
        string $description,
        ErrorImpactType $impactType,
        ?User $reporter,
    ) {
        $this->orgUnit = $orgUnit;
        $this->occurredOn = $occurredOn->setTime(0, 0);
        $this->description = $description;
        $this->impactType = $impactType;
        $this->reporter = $reporter;
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

    public function getOccurredOn(): \DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function getReporter(): ?User
    {
        return $this->reporter;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getImpactType(): ErrorImpactType
    {
        return $this->impactType;
    }

    public function setImpactType(ErrorImpactType $impactType): static
    {
        $this->impactType = $impactType;

        return $this;
    }

    public function getImpactAmount(): ?Number
    {
        return $this->impactAmount;
    }

    public function setImpactAmount(?Number $impactAmount): static
    {
        $this->impactAmount = $impactAmount;

        return $this;
    }

    public function getCause(): ?string
    {
        return $this->cause;
    }

    public function setCause(?string $cause): static
    {
        $this->cause = $cause;

        return $this;
    }

    public function getPrevention(): ?string
    {
        return $this->prevention;
    }

    public function setPrevention(?string $prevention): static
    {
        $this->prevention = $prevention;

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getStatus(): ErrorStatus
    {
        return $this->status;
    }

    public function setStatus(ErrorStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setOccurredOn(\DateTimeImmutable $occurredOn): static
    {
        $this->occurredOn = $occurredOn->setTime(0, 0);

        return $this;
    }
}
