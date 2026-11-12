<?php

declare(strict_types=1);

namespace App\Service\Rnp;

use App\DTO\OrgUnitDto;
use App\DTO\Rnp\DailyAverageDto;
use App\DTO\Rnp\DeviationNoteDto;
use App\DTO\Rnp\PaceDto;
use App\DTO\Rnp\RnpCellDto;
use App\DTO\Rnp\RnpRowDto;
use App\DTO\Rnp\RnpWeekDto;
use App\Entity\Auth\User;
use App\Entity\Metric\Metric;
use App\Enum\MetricDirection;
use App\Enum\PeriodType;
use App\Enum\TimeAggregation;
use App\Repository\Metric\MetricRepository;
use App\Security\Access\AccessScopeResolver;
use App\Service\Metric\Completion;
use App\Service\Metric\DeviationNoteHandler;
use App\Service\Metric\MetricEditPolicy;
use App\Service\Metric\MetricViewPrefs;
use App\Service\Metric\Values\MetricValueResolver;
use App\Service\Metric\Values\MetricValueResolverFactory;
use App\Service\Org\OrgUnitFindHandler;
use App\Service\Period\PeriodCalendar;
use App\Service\Period\WeekSegment;
use BcMath\Number;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Weekly RNP screen of one org unit: every active metric of the unit with week and month
 * plan / fact / completion / colour and the month run rate.
 */
final readonly class RnpWeekHandler
{
    public function __construct(
        private OrgUnitFindHandler $units,
        private MetricRepository $metrics,
        private MetricValueResolverFactory $resolvers,
        private PeriodCalendar $calendar,
        private AccessScopeResolver $scopes,
        private MetricViewPrefs $viewPrefs,
        private DeviationNoteHandler $notes,
    ) {
    }

    /**
     * @param bool $showHidden include metrics the user has hidden (flagged with hidden = true)
     */
    public function handle(int $orgUnitId, ?string $week, User $user, ?\DateTimeImmutable $today = null, bool $showHidden = false): RnpWeekDto
    {
        $unit = $this->units->handle($orgUnitId);
        $today = ($today ?? new \DateTimeImmutable())->setTime(0, 0);
        $segment = $this->calendar->weekSegmentFor($this->parseWeek($week) ?? $today);
        $monthStart = $segment->start->modify('first day of this month');
        $monthEnd = $monthStart->modify('last day of this month');

        $arranged = $this->viewPrefs->arrange($user, $this->metrics->findActiveByOrgUnits([(int) $unit->getId()]));
        $hiddenCount = \count(array_filter($arranged, static fn (array $i): bool => $i['hidden']));
        if (!$showHidden) {
            $arranged = array_values(array_filter($arranged, static fn (array $i): bool => !$i['hidden']));
        }

        $metrics = array_column($arranged, 'metric');
        $resolver = $this->resolvers->forRange($metrics, $monthStart, $monthEnd);
        $scope = $this->scopes->resolve($user);

        $notes = $this->notes->notesFor($metrics, $segment->start, $monthStart);

        $rows = [];
        foreach ($arranged as ['metric' => $metric, 'hidden' => $hidden]) {
            $row = $this->row($metric, $resolver, $segment, $monthStart, $today, MetricEditPolicy::canEnterFact($metric, $scope, $user), MetricEditPolicy::canSetPlan($metric, $scope), $hidden);
            $note = $notes[(int) $metric->getId()] ?? null;
            $rows[] = $row->withDeviation(null === $note ? null : DeviationNoteDto::fromEntity($note), MetricEditPolicy::canExplainDeviation($metric, $scope, $user));
        }

        $prev = $segment->start->modify('-1 day');

        return new RnpWeekDto(
            OrgUnitDto::fromEntity($unit),
            $segment->index,
            $segment->start->format('Y-m-d'),
            $segment->end->format('Y-m-d'),
            $monthStart->format('Y-m-d'),
            $this->calendar->weekSegmentFor($prev)->start->format('Y-m-d'),
            $segment->end->modify('+1 day')->format('Y-m-d'),
            $segment->contains($today),
            $rows,
            $hiddenCount,
        );
    }

    private function row(Metric $metric, MetricValueResolver $resolver, WeekSegment $segment, \DateTimeImmutable $monthStart, \DateTimeImmutable $today, bool $canEnterFact, bool $canSetPlan, bool $hidden): RnpRowDto
    {
        [$green, $red] = $resolver->data()->threshold($metric);
        $direction = $metric->getDirection();
        $monthly = PeriodType::Month === $metric->getGranularity();

        $week = null;
        if (!$monthly) {
            $plan = $resolver->weekPlan($metric, $segment->start);
            $fact = $resolver->weekFact($metric, $segment->start);
            $pct = Completion::percent($plan, $fact, $direction);
            $week = new RnpCellDto(RnpCellDto::decimal($plan), RnpCellDto::decimal($fact), RnpCellDto::decimal($pct, 1), Completion::color($pct, $green, $red));
        }

        // A plan formula follows the facts, so the month plan is taken on the same days as the month fact;
        // such a metric has no run rate or daily pace (a lagging target: a pace to a moving plan means nothing).
        $monthPlan = $resolver->monthPlan($metric, $monthStart, $segment->start);
        $monthFact = $resolver->monthFact($metric, $monthStart, $segment->start);
        $monthPct = Completion::percent($monthPlan, $monthFact, $direction);

        $planComputed = $resolver->isMonthPlanComputed($metric, $monthStart);
        $until = $this->elapsedUntil($metric, $resolver, $segment, $monthStart, $today);
        $runRate = $planComputed || null === $until ? null : $this->runRate($metric, $monthFact, $monthStart, $until);
        $runRatePct = Completion::percent($monthPlan, $runRate, $direction);

        return new RnpRowDto(
            (int) $metric->getId(),
            $metric->getCode(),
            $metric->getName(),
            $metric->getUnit()->value,
            $direction->value,
            $metric->getTimeAgg()->value,
            $metric->getGranularity()->value,
            $metric->getSourceType()->value,
            $metric->isManual(),
            null !== $metric->getFormula(),
            [] !== $resolver->data()->children($metric),
            $resolver->storedOrigin($metric, $monthly ? PeriodType::Month : PeriodType::Week, $monthly ? $monthStart : $segment->start),
            $canEnterFact,
            $canSetPlan,
            $week,
            new RnpCellDto(RnpCellDto::decimal($monthPlan), RnpCellDto::decimal($monthFact), RnpCellDto::decimal($monthPct, 1), Completion::color($monthPct, $green, $red)),
            RnpCellDto::decimal($runRate),
            RnpCellDto::decimal($runRatePct, 1),
            Completion::color($runRatePct, $green, $red),
            $hidden,
            pace: null === $runRate ? null : $this->pace($metric, $monthPlan, $monthFact, $monthStart, $until),
            planFormula: $planComputed ? $metric->getPlanFormula() : null,
            dailyAverage: $this->dailyAverage($metric, $resolver, $segment, $monthFact, $monthStart, $until, $today),
        );
    }

    /**
     * Last day the month fact covers: the shown week's end, or today while that week is running. A manual
     * metric covers only the weeks somebody has entered: a week still waiting for its fact must not count
     * as days without results (that understated the run rate and raised false "behind plan" alerts).
     * Null when nothing of the month is covered yet.
     */
    private function elapsedUntil(Metric $metric, MetricValueResolver $resolver, WeekSegment $segment, \DateTimeImmutable $monthStart, \DateTimeImmutable $today): ?\DateTimeImmutable
    {
        if ($segment->start > $today) {
            return null;
        }
        $until = $segment->end < $today ? $segment->end : $today;
        if (!$metric->isManual() || PeriodType::Month === $metric->getGranularity()) {
            return $until;
        }

        $covered = null;
        foreach ($this->calendar->monthWeeks((int) $monthStart->format('Y'), (int) $monthStart->format('n')) as $week) {
            if ($week->start <= $until && null !== $resolver->storedOrigin($metric, PeriodType::Week, $week->start)) {
                $covered = $week->end < $until ? $week->end : $until;
            }
        }

        return $covered;
    }

    /**
     * Additive weekly metrics only: fact to date / elapsed days × days in month (elapsed days: elapsedUntil).
     */
    private function runRate(Metric $metric, ?Number $monthFact, \DateTimeImmutable $monthStart, \DateTimeImmutable $until): ?Number
    {
        if (null === $monthFact || null !== $metric->getFormula() || TimeAggregation::Sum !== $metric->getTimeAgg()
            || PeriodType::Month === $metric->getGranularity()) {
            return null;
        }

        $elapsed = (int) $monthStart->diff($until)->days + 1;
        $days = $this->calendar->daysInMonth((int) $monthStart->format('Y'), (int) $monthStart->format('n'));

        return ($monthFact * $days)->div($elapsed, 10);
    }

    private function parseWeek(?string $week): ?\DateTimeImmutable
    {
        if (null === $week || '' === $week) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $week);
        if (false === $date || $date->format('Y-m-d') !== $week) {
            throw new BadRequestHttpException('Parameter "week" must be a date in Y-m-d format.');
        }

        return $date;
    }

    /**
     * Pace to the month plan, on the same elapsed days as the run rate: what each remaining day has to bring
     * (higher is better) or may still cost (lower is better). Null without a plan or when the month is over.
     */
    private function pace(Metric $metric, ?Number $plan, ?Number $fact, \DateTimeImmutable $monthStart, \DateTimeImmutable $until): ?PaceDto
    {
        if (null === $plan || null === $fact) {
            return null;
        }

        $elapsed = (int) $monthStart->diff($until)->days + 1;
        $days = $this->calendar->daysInMonth((int) $monthStart->format('Y'), (int) $monthStart->format('n'));
        $remaining = $days - $elapsed;
        if ($remaining <= 0) {
            return null;
        }

        $left = $plan - $fact;
        $reached = MetricDirection::LowerBetter === $metric->getDirection() ? $left->compare(0) < 0 : $left->compare(0) <= 0;
        $required = $left->compare(0) > 0 ? $left->div($remaining, 10) : new Number('0');

        return new PaceDto(
            (string) RnpCellDto::decimal($fact->div($elapsed, 10)),
            (string) RnpCellDto::decimal($required),
            $remaining,
            $reached,
        );
    }

    /**
     * Average per day for metrics that ask for it (additive weekly ones without a formula): the week fact over the
     * days of the segment — for a computed metric of the running week, over the days up to today — and the month
     * fact over the days it covers (elapsedUntil, as the run rate).
     */
    private function dailyAverage(Metric $metric, MetricValueResolver $resolver, WeekSegment $segment, ?Number $monthFact, \DateTimeImmutable $monthStart, ?\DateTimeImmutable $until, \DateTimeImmutable $today): ?DailyAverageDto
    {
        if (!$metric->isShowDailyAvg() || null !== $metric->getFormula() || TimeAggregation::Sum !== $metric->getTimeAgg()
            || PeriodType::Month === $metric->getGranularity()) {
            return null;
        }

        $week = null;
        $weekFact = $resolver->weekFact($metric, $segment->start);
        if (null !== $weekFact && $segment->start <= $today) {
            $last = $metric->isManual() || $segment->end < $today ? $segment->end : $today;
            $week = RnpCellDto::decimal($weekFact->div((int) $segment->start->diff($last)->days + 1, 10), 1);
        }
        $month = null === $monthFact || null === $until
            ? null
            : RnpCellDto::decimal($monthFact->div((int) $monthStart->diff($until)->days + 1, 10), 1);

        return null === $week && null === $month ? null : new DailyAverageDto($week, $month);
    }
}
