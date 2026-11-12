<?php

declare(strict_types=1);

namespace App\Entity\Metric;

use App\Entity\Auth\User;
use App\Repository\Metric\UserMetricPrefRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-user visibility and order of metrics on the RNP screen.
 */
#[ORM\Entity(repositoryClass: UserMetricPrefRepository::class)]
#[ORM\Table(name: 'user_metric_pref')]
#[ORM\UniqueConstraint(name: 'uniq_user_metric_pref', columns: ['user_id', 'metric_id'])]
class UserMetricPref
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Metric::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Metric $metric;

    #[ORM\Column(options: ['default' => true])]
    private bool $visible = true;

    #[ORM\Column(options: ['default' => 0])]
    private int $sort = 0;

    public function __construct(User $user, Metric $metric)
    {
        $this->user = $user;
        $this->metric = $metric;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getMetric(): Metric
    {
        return $this->metric;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function setVisible(bool $visible): static
    {
        $this->visible = $visible;

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
