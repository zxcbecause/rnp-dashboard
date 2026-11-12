<?php

declare(strict_types=1);

namespace App\Service\Metric;

use App\Entity\Metric\Metric;
use App\Entity\Metric\MetricValue;
use App\Enum\MetricSourceType;
use App\Enum\PeriodType;
use App\Enum\TimeAggregation;
use App\Enum\ValueOrigin;
use App\Repository\Metric\MetricValueRepository;
use App\Service\Metric\Calculation\SourceMetricCalculator;
use App\Service\Period\PeriodCalendar;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Lock\LockFactory;

/**
 * Writes weekly facts (month-cut week segments) of automatic metrics (platform_api, bitrix)
 * from the raw snapshots, through the SourceMetricCalculator of each source type.
 *
 *  - stored metrics are computed ("sum", and "avg" / "last" when their calculator gives a value per period);
 *    formula metrics are derived at read time;
 *  - a point-in-time value (a forecast, SourceMetricCalculator::isPointInTime) is written for the running period
 *    only: finished periods keep the value they ended with;
 *  - "monthly_cohort" metrics (granularity month) get one value per month, recomputed while the
 *    month is inside the window: a cohort keeps growing as its deals progress;
 *  - the current segment holds the value to date;
 *  - origin = auto; a manual correction is never overwritten;
 *  - the month fact is the sum of its segments, so months are not stored separately.
 */
final readonly class AutoMetricRecalculator
{
    /** Months a monthly cohort is recounted back from today, whatever window the run was asked for. */
    private const int COHORT_MONTHS = 12;

    /** @var list<SourceMetricCalculator> */
    private array $calculators;

    /**
     * @param iterable<SourceMetricCalculator> $calculators
     */
    public function __construct(
        private EntityManagerInterface $em,
        private MetricValueRepository $values,
        #[AutowireIterator(SourceMetricCalculator::TAG)]
        iterable $calculators,
        private PeriodCalendar $calendar,
        private LoggerInterface $logger,
        private LockFactory $locks,
    ) {
        $this->calculators = iterator_to_array($calculators, false);
    }

    public function recalculate(\DateTimeImmutable $from, ?\DateTimeImmutable $today = null): RecalculationResult
    {
        $today = ($today ?? new \DateTimeImmutable())->setTime(0, 0);
        $result = new RecalculationResult();

        // The platform and Bitrix syncs both recalculate; two concurrent runs would insert the same
        // new periods and the second flush would fail on the unique period key. Wait for the other one.
        $lock = $this->locks->createLock('metrics-recalc', 900);
        $lock->acquire(true);
        try {
            foreach ($this->calculators as $calculator) {
                foreach ($this->metricsOf($calculator) as $metric) {
                    $this->recalculateIfComputable($calculator, $metric, $from, $today, $result);
                }
            }

            $this->em->flush();
        } finally {
            $lock->release();
        }

        return $result;
    }

    private function recalculateIfComputable(SourceMetricCalculator $calculator, Metric $metric, \DateTimeImmutable $from, \DateTimeImmutable $today, RecalculationResult $result): void
    {
        try {
            $config = $calculator->parseConfig($metric->getSourceConfig());
        } catch (\InvalidArgumentException $e) {
            $result->errors[$metric->getCode()] = $e->getMessage();
            $this->logger->error('Metric {code}: invalid source_config: {message}', ['code' => $metric->getCode(), 'message' => $e->getMessage()]);

            return;
        }

        if (null === $config) {
            $result->skipped[$metric->getCode()] = 'no calculator in source_config';

            return;
        }

        if (!$calculator->hasSourceData($config)) {
            $result->skipped[$metric->getCode()] = 'source not synced yet';

            return;
        }

        $cohort = TimeAggregation::MonthlyCohort === $metric->getTimeAgg();
        if (!\in_array($metric->getTimeAgg(), [TimeAggregation::Sum, TimeAggregation::Avg, TimeAggregation::Last], true) && !$cohort) {
            $result->skipped[$metric->getCode()] = \sprintf('time_agg "%s" is not computed from raw data', $metric->getTimeAgg()->value);

            return;
        }
        if ($cohort && PeriodType::Month !== $metric->getGranularity()) {
            $result->errors[$metric->getCode()] = 'monthly_cohort metrics must have granularity "month"';

            return;
        }

        if ($cohort) {
            // A cohort keeps growing while its deals advance (a deal of August may sign in November): the
            // regular window would freeze it, so cohorts are always recounted for the last year.
            $cohortFrom = $today->modify(\sprintf('first day of -%d months', self::COHORT_MONTHS))->setTime(0, 0);
            $from = $cohortFrom < $from ? $cohortFrom : $from;
        }

        $this->recalculateMetric($calculator, $metric, $config, $from, $today, $result);
    }

    private function recalculateMetric(SourceMetricCalculator $calculator, Metric $metric, object $config, \DateTimeImmutable $from, \DateTimeImmutable $today, RecalculationResult $result): void
    {
        $type = PeriodType::Month === $metric->getGranularity() ? PeriodType::Month : PeriodType::Week;
        $existing = $this->values->findIndexedByStart($metric, $type, $from->modify('first day of this month'), $today);
        $periods = PeriodType::Month === $type ? $this->months($from, $today) : $this->segments($from, $today);

        $pointInTime = $calculator->isPointInTime($config);

        foreach ($periods as [$start, $end]) {
            // $end is exclusive: a period that ends today or earlier is over.
            if ($pointInTime && $end <= $today) {
                continue;
            }
            $value = $calculator->calculate($config, $start, $end);
            $current = $existing[$start->format('Y-m-d')] ?? null;

            if (null === $current) {
                $this->em->persist(new MetricValue($metric, $type, $start, $value, ValueOrigin::Auto));
                ++$result->written;
            } elseif (ValueOrigin::Manual === $current->getOrigin()) {
                ++$result->keptManual;
            } elseif ($current->getValue() != $value || ValueOrigin::Auto !== $current->getOrigin()) {
                $current->update($value, ValueOrigin::Auto, null);
                ++$result->written;
            } else {
                ++$result->unchanged;
            }
        }

        $result->metrics[] = $metric->getCode();
    }

    /**
     * Week segments from the month of $from up to today; the current one ends today (inclusive).
     *
     * @return \Generator<array{\DateTimeImmutable, \DateTimeImmutable}> [start, end exclusive]
     */
    private function segments(\DateTimeImmutable $from, \DateTimeImmutable $today): \Generator
    {
        $month = $this->calendar->monthStart((int) $from->format('Y'), (int) $from->format('n'));
        $tomorrow = $today->modify('+1 day');

        while ($month <= $today) {
            foreach ($this->calendar->monthWeeks((int) $month->format('Y'), (int) $month->format('n')) as $week) {
                if ($week->start > $today) {
                    return;
                }
                $end = $week->end->modify('+1 day');
                yield [$week->start, $end < $tomorrow ? $end : $tomorrow];
            }
            $month = $month->modify('first day of next month');
        }
    }

    /**
     * @return list<Metric>
     */
    private function metricsOf(SourceMetricCalculator $calculator): array
    {
        /** @var list<Metric> $metrics */
        $metrics = $this->em->getRepository(Metric::class)->findBy(
            ['sourceType' => $calculator->sourceType(), 'isActive' => true],
            ['id' => 'ASC'],
        );

        return $metrics;
    }

    /**
     * Months from the month of $from up to the current one, which ends today (inclusive).
     *
     * @return \Generator<array{\DateTimeImmutable, \DateTimeImmutable}> [start, end exclusive]
     */
    private function months(\DateTimeImmutable $from, \DateTimeImmutable $today): \Generator
    {
        $month = $this->calendar->monthStart((int) $from->format('Y'), (int) $from->format('n'));
        $tomorrow = $today->modify('+1 day');

        while ($month <= $today) {
            $next = $month->modify('first day of next month');
            yield [$month, $next < $tomorrow ? $next : $tomorrow];
            $month = $next;
        }
    }

    /**
     * Recalculates the metrics of one source right away (e.g. after a call exclusion is approved) without waiting
     * for another run: returns null when a sync is recalculating now — that run, or the next sync within its
     * window, picks the change up.
     */
    public function tryRecalculateSource(MetricSourceType $source, \DateTimeImmutable $from, ?\DateTimeImmutable $today = null): ?RecalculationResult
    {
        $today = ($today ?? new \DateTimeImmutable())->setTime(0, 0);
        $lock = $this->locks->createLock('metrics-recalc', 900);
        if (!$lock->acquire()) {
            return null;
        }

        $result = new RecalculationResult();
        try {
            foreach ($this->calculators as $calculator) {
                if ($calculator->sourceType() !== $source) {
                    continue;
                }
                foreach ($this->metricsOf($calculator) as $metric) {
                    $this->recalculateIfComputable($calculator, $metric, $from, $today, $result);
                }
            }

            $this->em->flush();
        } finally {
            $lock->release();
        }

        return $result;
    }
}
