<?php

declare(strict_types=1);

namespace App\Entity\Metric;

use BcMath\Number;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Colour thresholds in percent points of plan completion (100 = plan met).
 * metric = NULL is the global default; a metric row overrides it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'metric_threshold')]
#[ORM\UniqueConstraint(name: 'uniq_metric_threshold_metric', columns: ['metric_id'])]
class MetricThreshold
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Metric::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Metric $metric;

    /** Completion >= this value is green. */
    #[ORM\Column(type: Types::NUMBER, precision: 7, scale: 2)]
    private Number $greenFromPct;

    /** Completion < this value is red; between the two is neutral. */
    #[ORM\Column(type: Types::NUMBER, precision: 7, scale: 2)]
    private Number $redBelowPct;

    public function __construct(?Metric $metric, Number $greenFromPct, Number $redBelowPct)
    {
        $this->metric = $metric;
        $this->setBounds($greenFromPct, $redBelowPct);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMetric(): ?Metric
    {
        return $this->metric;
    }

    public function getGreenFromPct(): Number
    {
        return $this->greenFromPct;
    }

    public function getRedBelowPct(): Number
    {
        return $this->redBelowPct;
    }

    public function setBounds(Number $greenFromPct, Number $redBelowPct): void
    {
        if ($redBelowPct > $greenFromPct) {
            throw new \InvalidArgumentException('red_below_pct must not exceed green_from_pct.');
        }

        $this->greenFromPct = $greenFromPct;
        $this->redBelowPct = $redBelowPct;
    }
}
