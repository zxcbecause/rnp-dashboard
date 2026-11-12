<?php

declare(strict_types=1);

namespace App\Service\Metric\Values;

use App\Entity\Metric\Metric;
use App\Enum\PeriodType;
use App\Enum\TimeAggregation;
use App\Service\Metric\Formula\FormulaEvaluator;
use App\Service\Period\PeriodCalendar;
use BcMath\Number;

/**
 * Computes fact and plan of a metric for a week segment or a month from a MetricDataSet.
 *
 * Fact:
 *  - formula metrics: the formula over the components' facts of the same period (ratios are
 *    never stored nor averaged);
 *  - week: the stored week value, otherwise the sum of the roll-up children;
 *  - month: a stored month value (monthly metrics), otherwise the week segments of the month
 *    aggregated by time_agg (sum / avg / last), otherwise the roll-up children.
 *
 * Plan: set plan of that period → (week) month plan, prorated by days for sum metrics, the same target
 * otherwise (ratios too) / (month) sum of set week plans for sum metrics → plan formula (facts of the period ×
 * set plans of other metrics) → formula over component plans → roll-up children. A set plan wins over the plan
 * formula, so months planned before the formula existed keep their plan and a head can override it.
 */
final readonly class MetricValueResolver
{
    private const int MAX_DEPTH = 8;

    public function __construct(
        private MetricDataSet $data,
        private FormulaEvaluator $formulas,
        private PeriodCalendar $calendar,
    ) {
    }

    public function data(): MetricDataSet
    {
        return $this->data;
    }

    public function weekFact(Metric $metric, \DateTimeImmutable $start, int $depth = 0): ?Number
    {
        $this->guard($depth);

        if (null !== $metric->getFormula()) {
            return $this->formula($metric, fn (Metric $c): ?Number => $this->weekFact($c, $start, $depth + 1));
        }

        $stored = $this->data->storedValue($metric, PeriodType::Week->value, $start->format('Y-m-d'));
        if (null !== $stored) {
            return $stored->getValue();
        }

        return $this->sumChildren($metric, fn (Metric $c): ?Number => $this->weekFact($c, $start, $depth + 1));
    }

    /**
     * @param \DateTimeImmutable|null $upTo only week segments starting on or before this day count (month to date)
     */
    public function monthFact(Metric $metric, \DateTimeImmutable $monthStart, ?\DateTimeImmutable $upTo = null, int $depth = 0): ?Number
    {
        $this->guard($depth);

        if (null !== $metric->getFormula()) {
            return $this->formula($metric, fn (Metric $c): ?Number => $this->monthFact($c, $monthStart, $upTo, $depth + 1));
        }

        $stored = $this->data->storedValue($metric, PeriodType::Month->value, $monthStart->format('Y-m-d'));
        if (null !== $stored) {
            return $stored->getValue();
        }

        $weeks = [];
        foreach ($this->segments($monthStart) as $segment) {
            if (null !== $upTo && $segment->start > $upTo) {
                break;
            }
            $value = $this->data->storedValue($metric, PeriodType::Week->value, $segment->start->format('Y-m-d'));
            if (null !== $value) {
                $weeks[] = $value->getValue();
            }
        }

        if ([] !== $weeks) {
            return match ($metric->getTimeAgg()) {
                TimeAggregation::Avg => $this->sum($weeks)?->div(\count($weeks), 10),
                TimeAggregation::Last => $weeks[\count($weeks) - 1],
                default => $this->sum($weeks),
            };
        }

        return $this->sumChildren($metric, fn (Metric $c): ?Number => $this->monthFact($c, $monthStart, $upTo, $depth + 1));
    }

    public function weekPlan(Metric $metric, \DateTimeImmutable $start, int $depth = 0): ?Number
    {
        $this->guard($depth);

        $explicit = $this->explicitWeekPlan($metric, $start);
        if (null !== $explicit) {
            return $explicit;
        }

        if (null !== $metric->getPlanFormula()) {
            return $this->planFormula(
                $metric,
                fn (Metric $c): ?Number => $this->weekFact($c, $start, $depth + 1),
                fn (Metric $c): ?Number => $this->explicitWeekPlan($c, $start),
            );
        }

        if (null !== $metric->getFormula()) {
            return $this->formula($metric, fn (Metric $c): ?Number => $this->weekPlan($c, $start, $depth + 1));
        }

        return $this->sumChildren($metric, fn (Metric $c): ?Number => $this->weekPlan($c, $start, $depth + 1));
    }

    /**
     * @param \DateTimeImmutable|null $upTo plan formulas only: facts of the month to date (as monthFact)
     */
    public function monthPlan(Metric $metric, \DateTimeImmutable $monthStart, ?\DateTimeImmutable $upTo = null, int $depth = 0): ?Number
    {
        $this->guard($depth);

        $explicit = $this->explicitMonthPlan($metric, $monthStart);
        if (null !== $explicit) {
            return $explicit;
        }

        if (null !== $metric->getPlanFormula()) {
            return $this->planFormula(
                $metric,
                fn (Metric $c): ?Number => $this->monthFact($c, $monthStart, $upTo, $depth + 1),
                fn (Metric $c): ?Number => $this->explicitMonthPlan($c, $monthStart),
            );
        }

        if (null !== $metric->getFormula()) {
            return $this->formula($metric, fn (Metric $c): ?Number => $this->monthPlan($c, $monthStart, $upTo, $depth + 1));
        }

        return $this->sumChildren($metric, fn (Metric $c): ?Number => $this->monthPlan($c, $monthStart, $upTo, $depth + 1));
    }

    /**
     * Whether a fact for this period is stored manually (shown as "вручную").
     */
    public function storedOrigin(Metric $metric, PeriodType $type, \DateTimeImmutable $start): ?string
    {
        return $this->data->storedValue($metric, $type->value, $start->format('Y-m-d'))?->getOrigin()->value;
    }

    /**
     * @return list<\App\Service\Period\WeekSegment>
     */
    private function segments(\DateTimeImmutable $monthStart): array
    {
        return $this->calendar->monthWeeks((int) $monthStart->format('Y'), (int) $monthStart->format('n'));
    }

    /**
     * @param callable(Metric): ?Number $value
     */
    private function formula(Metric $metric, callable $value): ?Number
    {
        return $this->formulas->evaluate((string) $metric->getFormula(), function (string $code) use ($value): ?Number {
            $component = $this->data->byCode($code);

            return null === $component ? null : $value($component);
        });
    }

    /**
     * @param callable(Metric): ?Number $value
     */
    private function sumChildren(Metric $metric, callable $value): ?Number
    {
        $children = $this->data->children($metric);
        if ([] === $children) {
            return null;
        }

        return $this->sum(array_values(array_filter(array_map($value, $children), static fn (?Number $n): bool => null !== $n)));
    }

    /**
     * @param list<Number> $numbers
     */
    private function sum(array $numbers): ?Number
    {
        if ([] === $numbers) {
            return null;
        }

        $total = new Number('0');
        foreach ($numbers as $number) {
            $total += $number;
        }

        return $total;
    }

    private function guard(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \LogicException('Metric formula or roll-up nesting is too deep (cycle?).');
        }
    }

    /**
     * Plan set by hand (or imported) for the week segment: its own, otherwise the month plan — prorated by days
     * for sum metrics, the same target every week for averages, states, cohorts and ratios.
     */
    private function explicitWeekPlan(Metric $metric, \DateTimeImmutable $start): ?Number
    {
        $stored = $this->data->storedPlan($metric, PeriodType::Week->value, $start->format('Y-m-d'));
        if (null !== $stored) {
            return $stored;
        }

        $monthStart = $start->modify('first day of this month');
        $monthPlan = $this->data->storedPlan($metric, PeriodType::Month->value, $monthStart->format('Y-m-d'));
        if (null === $monthPlan || TimeAggregation::Sum !== $metric->getTimeAgg()) {
            return $monthPlan;
        }

        $segment = $this->calendar->weekSegmentFor($start);
        $days = $this->calendar->daysInMonth((int) $monthStart->format('Y'), (int) $monthStart->format('n'));

        return ($monthPlan * $segment->days())->div($days, 10);
    }

    /**
     * Plan set by hand (or imported) for the month: its own, otherwise the sum of set week plans (sum metrics).
     * With a plan formula only a fully planned month counts: overriding one week must not turn that week's
     * plan into the plan of the whole month.
     */
    private function explicitMonthPlan(Metric $metric, \DateTimeImmutable $monthStart): ?Number
    {
        $stored = $this->data->storedPlan($metric, PeriodType::Month->value, $monthStart->format('Y-m-d'));
        if (null !== $stored || TimeAggregation::Sum !== $metric->getTimeAgg()) {
            return $stored;
        }

        $weeks = [];
        $segments = $this->segments($monthStart);
        foreach ($segments as $segment) {
            $plan = $this->data->storedPlan($metric, PeriodType::Week->value, $segment->start->format('Y-m-d'));
            if (null !== $plan) {
                $weeks[] = $plan;
            }
        }
        if (null !== $metric->getPlanFormula() && \count($weeks) < \count($segments)) {
            return null;
        }

        return $this->sum($weeks);
    }

    /**
     * Plan formula: a bare code is that metric's fact of the period, "code.plan" its explicitly set plan
     * (never another plan formula, so plan formulas cannot loop).
     *
     * @param callable(Metric): ?Number $fact
     * @param callable(Metric): ?Number $plan
     */
    private function planFormula(Metric $metric, callable $fact, callable $plan): ?Number
    {
        return $this->formulas->evaluate((string) $metric->getPlanFormula(), function (string $identifier) use ($fact, $plan): ?Number {
            $component = $this->data->byCode(FormulaEvaluator::code($identifier));
            if (null === $component) {
                return null;
            }

            return FormulaEvaluator::isPlanReference($identifier) ? $plan($component) : $fact($component);
        });
    }

    /**
     * Whether the month plan comes from the plan formula (no plan set for that month).
     */
    public function isMonthPlanComputed(Metric $metric, \DateTimeImmutable $monthStart): bool
    {
        return null !== $metric->getPlanFormula() && null === $this->explicitMonthPlan($metric, $monthStart);
    }
}
