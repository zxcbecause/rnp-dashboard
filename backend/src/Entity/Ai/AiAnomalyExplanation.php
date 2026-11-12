<?php

declare(strict_types=1);

namespace App\Entity\Ai;

use App\Entity\Auth\User;
use App\Entity\Metric\Metric;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * AI explanation of an alert about a metric (feature D): why the week is red or the month forecast is behind.
 * One per metric, kind and period; reused while the metric's figures stay the same (inputHash), so opening the bell
 * again costs nothing, and regenerated once they change.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_anomaly_explanation')]
#[ORM\UniqueConstraint(name: 'uniq_ai_anomaly_metric_kind_period', columns: ['metric_id', 'kind', 'period_start'])]
class AiAnomalyExplanation
{
    public const string KIND_WEEK = 'week';
    public const string KIND_FORECAST = 'forecast';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Metric::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Metric $metric;

    /** "week" (the week segment is red) or "forecast" (the month run rate is behind the plan). */
    #[ORM\Column(length: 16)]
    private string $kind;

    /** Week segment start (week) or month start (forecast). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodStart;

    /** SHA-1 of the metric's figures the explanation was made from. */
    #[ORM\Column(length: 40)]
    private string $inputHash;

    /** Markdown: a short bullet list. */
    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    #[ORM\Column(length: 64)]
    private string $model;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Metric $metric, string $kind, \DateTimeImmutable $periodStart, string $inputHash, string $text, string $model, ?User $createdBy)
    {
        $this->metric = $metric;
        $this->kind = $kind;
        $this->periodStart = $periodStart->setTime(0, 0);
        $this->inputHash = $inputHash;
        $this->text = $text;
        $this->model = $model;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMetric(): Metric
    {
        return $this->metric;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getPeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function getInputHash(): string
    {
        return $this->inputHash;
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

    public function replace(string $inputHash, string $text, string $model, ?User $createdBy): self
    {
        $this->inputHash = $inputHash;
        $this->text = $text;
        $this->model = $model;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();

        return $this;
    }
}
