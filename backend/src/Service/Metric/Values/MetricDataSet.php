<?php

declare(strict_types=1);

namespace App\Service\Metric\Values;

use App\Entity\Metric\Metric;
use App\Entity\Metric\MetricPlan;
use App\Entity\Metric\MetricThreshold;
use App\Entity\Metric\MetricValue;
use BcMath\Number;

/**
 * In-memory snapshot produced by MetricDataLoader.
 */
final class MetricDataSet
{
    /** @var array<string, Metric> */
    private array $byCode = [];

    /** @var array<int, array<string, array<string, MetricValue>>> metric id => period type => Y-m-d => value */
    private array $values = [];

    /** @var array<int, array<string, array<string, Number>>> */
    private array $plans = [];

    /** @var array{0: Number, 1: Number} */
    private array $defaultThreshold;

    /** @var array<int, array{0: Number, 1: Number}> */
    private array $thresholds = [];

    /**
     * @param array<int, Metric>    $metrics
     * @param array<int, list<int>> $children
     * @param list<MetricValue>     $values
     * @param list<MetricPlan>      $plans
     * @param list<MetricThreshold> $thresholds
     */
    public function __construct(
        private readonly array $metrics,
        private readonly array $children,
        array $values,
        array $plans,
        array $thresholds,
    ) {
        foreach ($metrics as $metric) {
            $this->byCode[$metric->getCode()] = $metric;
        }
        foreach ($values as $value) {
            $this->values[(int) $value->getMetric()->getId()][$value->getPeriodType()->value][$value->getPeriodStart()->format('Y-m-d')] = $value;
        }
        foreach ($plans as $plan) {
            $this->plans[(int) $plan->getMetric()->getId()][$plan->getPeriodType()->value][$plan->getPeriodStart()->format('Y-m-d')] = $plan->getValue();
        }

        $this->defaultThreshold = [new Number('100'), new Number('90')];
        foreach ($thresholds as $threshold) {
            $pair = [$threshold->getGreenFromPct(), $threshold->getRedBelowPct()];
            $metricId = $threshold->getMetric()?->getId();
            if (null === $metricId) {
                $this->defaultThreshold = $pair;
            } else {
                $this->thresholds[$metricId] = $pair;
            }
        }
    }

    public function byCode(string $code): ?Metric
    {
        return $this->byCode[$code] ?? null;
    }

    /**
     * @return list<Metric>
     */
    public function children(Metric $metric): array
    {
        return array_values(array_filter(array_map(
            fn (int $id): ?Metric => $this->metrics[$id] ?? null,
            $this->children[(int) $metric->getId()] ?? [],
        )));
    }

    public function storedValue(Metric $metric, string $periodType, string $start): ?MetricValue
    {
        return $this->values[(int) $metric->getId()][$periodType][$start] ?? null;
    }

    /**
     * @return array<string, MetricValue> Y-m-d => value
     */
    public function storedValues(Metric $metric, string $periodType): array
    {
        return $this->values[(int) $metric->getId()][$periodType] ?? [];
    }

    public function storedPlan(Metric $metric, string $periodType, string $start): ?Number
    {
        return $this->plans[(int) $metric->getId()][$periodType][$start] ?? null;
    }

    /**
     * @return array{0: Number, 1: Number} [green_from_pct, red_below_pct]
     */
    public function threshold(Metric $metric): array
    {
        return $this->thresholds[(int) $metric->getId()] ?? $this->defaultThreshold;
    }
}
