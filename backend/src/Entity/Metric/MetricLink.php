<?php

declare(strict_types=1);

namespace App\Entity\Metric;

use Doctrine\ORM\Mapping as ORM;

/**
 * Department metric = roll-up of the linked metrics of child org units.
 */
#[ORM\Entity]
#[ORM\Table(name: 'metric_link')]
#[ORM\UniqueConstraint(name: 'uniq_metric_link', columns: ['parent_metric_id', 'child_metric_id'])]
class MetricLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Metric::class)]
    #[ORM\JoinColumn(name: 'parent_metric_id', nullable: false, onDelete: 'CASCADE')]
    private Metric $parentMetric;

    #[ORM\ManyToOne(targetEntity: Metric::class)]
    #[ORM\JoinColumn(name: 'child_metric_id', nullable: false, onDelete: 'CASCADE')]
    private Metric $childMetric;

    public function __construct(Metric $parentMetric, Metric $childMetric)
    {
        if ($parentMetric === $childMetric) {
            throw new \InvalidArgumentException('A metric cannot be linked to itself.');
        }

        $this->parentMetric = $parentMetric;
        $this->childMetric = $childMetric;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParentMetric(): Metric
    {
        return $this->parentMetric;
    }

    public function getChildMetric(): Metric
    {
        return $this->childMetric;
    }
}
