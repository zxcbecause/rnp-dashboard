<?php

declare(strict_types=1);

namespace App\Service\Metric\Values;

use App\Entity\Metric\Metric;
use App\Service\Metric\Formula\FormulaEvaluator;
use App\Service\Period\PeriodCalendar;

final readonly class MetricValueResolverFactory
{
    public function __construct(
        private MetricDataLoader $loader,
        private FormulaEvaluator $formulas,
        private PeriodCalendar $calendar,
    ) {
    }

    /**
     * @param list<Metric> $metrics
     */
    public function forRange(array $metrics, \DateTimeImmutable $from, \DateTimeImmutable $to): MetricValueResolver
    {
        return new MetricValueResolver($this->loader->load($metrics, $from, $to), $this->formulas, $this->calendar);
    }
}
