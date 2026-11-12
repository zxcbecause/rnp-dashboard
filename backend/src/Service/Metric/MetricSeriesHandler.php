<?php

declare(strict_types=1);

namespace App\Service\Metric;

use App\DTO\Rnp\RnpCellDto;
use App\Repository\Metric\MetricRepository;
use App\Security\Voter\MetricVoter;
use App\Service\Metric\Values\MetricValueResolverFactory;
use App\Service\Period\PeriodCalendar;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Plan / fact series of one metric for the trend chart (weeks or months).
 */
final readonly class MetricSeriesHandler
{
    private const int MAX_MONTHS = 24;

    public function __construct(
        private MetricRepository $metrics,
        private AuthorizationCheckerInterface $authorization,
        private MetricValueResolverFactory $resolvers,
        private PeriodCalendar $calendar,
    ) {
    }

    /**
     * With $compareYear every point also carries factPrevYear: the same month a year earlier, or for weeks
     * the week segment that contained the same date a year earlier (segments shift between years).
     *
     * @return list<array{periodStart: string, periodEnd: string, plan: ?string, fact: ?string, completionPct: ?string, color: ?string, factPrevYear: ?string}>
     */
    public function handle(int $metricId, string $period, ?string $from, ?string $to, ?\DateTimeImmutable $today = null, bool $compareYear = false): array
    {
        $metric = $this->metrics->find($metricId);
        if (null === $metric || !$this->authorization->isGranted(MetricVoter::VIEW, $metric)) {
            throw new NotFoundHttpException('Metric not found.');
        }
        if (!\in_array($period, ['week', 'month'], true)) {
            throw new BadRequestHttpException('Parameter "period" must be "week" or "month".');
        }

        $today = ($today ?? new \DateTimeImmutable())->setTime(0, 0);
        $end = ($this->date($to) ?? $today)->modify('last day of this month');
        $start = ($this->date($from) ?? $today->modify('first day of -2 months'))->modify('first day of this month');
        if ($start > $end) {
            throw new BadRequestHttpException('"from" must not be after "to".');
        }
        if ($start->diff($end)->y * 12 + $start->diff($end)->m >= self::MAX_MONTHS) {
            throw new BadRequestHttpException(\sprintf('The range is limited to %d months.', self::MAX_MONTHS));
        }

        $resolver = $this->resolvers->forRange([$metric], $compareYear ? $start->modify('-1 year') : $start, $end);
        [$green, $red] = $resolver->data()->threshold($metric);
        $points = [];

        for ($month = $start; $month <= $end; $month = $month->modify('first day of next month')) {
            if ('month' === $period) {
                $plan = $resolver->monthPlan($metric, $month);
                $fact = $resolver->monthFact($metric, $month);
                $prev = $compareYear ? $resolver->monthFact($metric, $month->modify('-1 year')) : null;
                $points[] = $this->point($month, $month->modify('last day of this month'), $plan, $fact, $metric->getDirection(), $green, $red) + ['factPrevYear' => RnpCellDto::decimal($prev)];
                continue;
            }

            foreach ($this->calendar->monthWeeks((int) $month->format('Y'), (int) $month->format('n')) as $week) {
                $prev = $compareYear ? $resolver->weekFact($metric, $this->calendar->weekSegmentFor($week->start->modify('-1 year'))->start) : null;
                $points[] = $this->point(
                    $week->start,
                    $week->end,
                    $resolver->weekPlan($metric, $week->start),
                    $resolver->weekFact($metric, $week->start),
                    $metric->getDirection(),
                    $green,
                    $red,
                ) + ['factPrevYear' => RnpCellDto::decimal($prev)];
            }
        }

        return $points;
    }

    /**
     * @return array{periodStart: string, periodEnd: string, plan: ?string, fact: ?string, completionPct: ?string, color: ?string}
     */
    private function point(\DateTimeImmutable $start, \DateTimeImmutable $end, ?\BcMath\Number $plan, ?\BcMath\Number $fact, \App\Enum\MetricDirection $direction, \BcMath\Number $green, \BcMath\Number $red): array
    {
        $pct = Completion::percent($plan, $fact, $direction);

        return [
            'periodStart' => $start->format('Y-m-d'),
            'periodEnd' => $end->format('Y-m-d'),
            'plan' => RnpCellDto::decimal($plan),
            'fact' => RnpCellDto::decimal($fact),
            'completionPct' => RnpCellDto::decimal($pct, 1),
            'color' => Completion::color($pct, $green, $red),
        ];
    }

    private function date(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException(\sprintf('Invalid date "%s", expected Y-m-d.', $value));
        }

        return $date;
    }
}
