<?php

declare(strict_types=1);

namespace App\Entity\Ai;

use App\Entity\Auth\User;
use App\Entity\Org\OrgUnit;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * AI review of one RNP week of a unit. Stored, so opening the cabinet again costs nothing; regenerated on request.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_weekly_review')]
#[ORM\UniqueConstraint(name: 'uniq_ai_weekly_review_unit_week', columns: ['org_unit_id', 'week_start'])]
class AiWeeklyReview
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrgUnit::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OrgUnit $orgUnit;

    /** Start of the RNP week segment (as in metric_value.period_start). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $weekStart;

    /** Markdown: short paragraphs and bullet lists. */
    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    #[ORM\Column(length: 64)]
    private string $model;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(OrgUnit $orgUnit, \DateTimeImmutable $weekStart, string $text, string $model, ?User $createdBy)
    {
        $this->orgUnit = $orgUnit;
        $this->weekStart = $weekStart->setTime(0, 0);
        $this->text = $text;
        $this->model = $model;
        $this->createdBy = $createdBy;
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

    public function getText(): string
    {
        return $this->text;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function replace(string $text, string $model, ?User $createdBy): self
    {
        $this->text = $text;
        $this->model = $model;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();

        return $this;
    }
}
