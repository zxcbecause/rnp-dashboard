<?php

declare(strict_types=1);

namespace App\Entity\Ai;

use App\Entity\Auth\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One request to the language model: who, which feature, how many tokens. The bill is read from here
 * (and per-user rate limits are enforced separately by the rate limiter).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_usage')]
#[ORM\Index(name: 'idx_ai_usage_created', columns: ['created_at'])]
class AiUsage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    /** weekly_review | deviation_draft | chat | anomaly */
    #[ORM\Column(length: 32)]
    private string $feature;

    #[ORM\Column(length: 64)]
    private string $model;

    #[ORM\Column]
    private int $inputTokens;

    #[ORM\Column]
    private int $outputTokens;

    #[ORM\Column]
    private int $cacheReadTokens;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(?User $user, string $feature, string $model, int $inputTokens, int $outputTokens, int $cacheReadTokens)
    {
        $this->user = $user;
        $this->feature = $feature;
        $this->model = $model;
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;
        $this->cacheReadTokens = $cacheReadTokens;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getFeature(): string
    {
        return $this->feature;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    public function getCacheReadTokens(): int
    {
        return $this->cacheReadTokens;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
