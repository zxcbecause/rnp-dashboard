<?php

declare(strict_types=1);

namespace App\Entity\Action;

use App\Entity\Auth\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'base_function_check')]
#[ORM\UniqueConstraint(name: 'uniq_base_function_check_period', columns: ['base_function_id', 'period_start'])]
class BaseFunctionCheck
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BaseFunction::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BaseFunction $baseFunction;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column]
    private bool $done;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'checked_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $checkedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $checkedAt;

    public function __construct(BaseFunction $baseFunction, \DateTimeImmutable $periodStart, bool $done, ?User $checkedBy = null)
    {
        $this->baseFunction = $baseFunction;
        $this->periodStart = $periodStart->setTime(0, 0);
        $this->done = $done;
        $this->checkedBy = $checkedBy;
        $this->checkedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBaseFunction(): BaseFunction
    {
        return $this->baseFunction;
    }

    public function getPeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getCheckedBy(): ?User
    {
        return $this->checkedBy;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function mark(bool $done, ?User $checkedBy): void
    {
        $this->done = $done;
        $this->checkedBy = $checkedBy;
        $this->checkedAt = new \DateTimeImmutable();
    }
}
