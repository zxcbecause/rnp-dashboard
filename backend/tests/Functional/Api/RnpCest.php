<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Metric\Metric;
use App\Entity\Metric\MetricLink;
use App\Entity\Metric\MetricPlan;
use App\Entity\Metric\MetricValue;
use App\Entity\Org\OrgMembership;
use App\Entity\Org\OrgUnit;
use App\Enum\MetricDirection;
use App\Enum\MetricSourceType;
use App\Enum\MetricUnit;
use App\Enum\OrgRole;
use App\Enum\PeriodType;
use App\Enum\TimeAggregation;
use App\Enum\ValueOrigin;
use App\Service\Org\OrgTreeManager;
use App\Tests\Support\FunctionalTester;
use BcMath\Number;

/**
 * October 2026 (a past month, so run rate does not depend on today):
 * segments 01–04, 05–11, 12–18, 19–25, 26–31.
 *
 *   Company ─ company_sum (roll-up of t_sum)
 *   └── Dept ─ t_sum (count, weekly, manual), t_num / t_den → t_ratio (formula),
 *              t_days (monthly, lower_better), t_prorated (only a month plan), t_auto (platform_api), t_pct (percent)
 */
final class RnpCest
{
    /** @var array<string, int> */
    private array $id = [];

    public function _before(FunctionalTester $I): void
    {
        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $company = $tree->createUnit('Company');
        $dept = $tree->createUnit('Dept', $company);
        $other = $tree->createUnit('Other', $company);
        $em = $I->grabEntityManager();

        $metric = function (OrgUnit $unit, string $code, MetricUnit $type, array $options = []) use ($em): Metric {
            $m = new Metric($unit, $code, $code, $type);
            $m->setSourceType($options['source'] ?? MetricSourceType::Manual)
                ->setTimeAgg($options['agg'] ?? TimeAggregation::Sum)
                ->setDirection($options['direction'] ?? MetricDirection::HigherBetter)
                ->setGranularity($options['granularity'] ?? PeriodType::Week)
                ->setFormula($options['formula'] ?? null)
                ->setSort(\count($this->id));
            $em->persist($m);
            $em->flush();
            $this->id[$code] = (int) $m->getId();

            return $m;
        };
        $fact = static fn (Metric $m, string $start, string $v, PeriodType $t = PeriodType::Week) => $em->persist(new MetricValue($m, $t, new \DateTimeImmutable($start), self::num($v), ValueOrigin::Import));
        $plan = static fn (Metric $m, string $start, string $v, PeriodType $t = PeriodType::Week) => $em->persist(new MetricPlan($m, $t, new \DateTimeImmutable($start), self::num($v)));

        $sum = $metric($dept, 't_sum', MetricUnit::Count);
        $fact($sum, '2026-10-01', '10');
        $fact($sum, '2026-10-05', '63');
        $plan($sum, '2026-10-05', '70');
        $plan($sum, '2026-10-01', '310', PeriodType::Month);

        $num = $metric($dept, 't_num', MetricUnit::Count);
        $den = $metric($dept, 't_den', MetricUnit::Count);
        $metric($dept, 't_ratio', MetricUnit::Percent, ['agg' => TimeAggregation::Formula, 'formula' => 't_num / t_den']);
        $fact($num, '2026-10-01', '5');
        $fact($num, '2026-10-05', '30');
        $fact($den, '2026-10-01', '10');
        $fact($den, '2026-10-05', '120');
        $plan($num, '2026-10-05', '25');
        $plan($den, '2026-10-05', '100');

        $days = $metric($dept, 't_days', MetricUnit::Days, ['granularity' => PeriodType::Month, 'direction' => MetricDirection::LowerBetter, 'agg' => TimeAggregation::Avg]);
        $fact($days, '2026-10-01', '4', PeriodType::Month);
        $plan($days, '2026-10-01', '5', PeriodType::Month);

        $prorated = $metric($dept, 't_prorated', MetricUnit::Count);
        $plan($prorated, '2026-10-01', '310', PeriodType::Month);

        $metric($dept, 't_auto', MetricUnit::Eur, ['source' => MetricSourceType::PlatformApi]);
        $metric($dept, 't_pct', MetricUnit::Percent);
        $metric($dept, 't_bool', MetricUnit::Boolean, ['granularity' => PeriodType::Month]);

        $rollup = $metric($company, 'company_sum', MetricUnit::Count);
        $em->persist(new MetricLink($rollup, $sum));
        $metric($other, 'other_metric', MetricUnit::Count);
        $em->flush();

        $this->id['company'] = (int) $company->getId();
        $this->id['dept'] = (int) $dept->getId();

        foreach (['head' => OrgRole::Head, 'member' => OrgRole::Member, 'viewer' => OrgRole::Viewer] as $name => $role) {
            $user = $I->haveUser($name.'@rnp.local');
            $em->persist(new OrgMembership($user, $dept, $role));
        }
        $em->flush();
    }

    public function weekScreenComputesPlanFactCompletionAndRunRate(FunctionalTester $I): void
    {
        $rows = $this->rnp($I, 'head', 'dept', '2026-10-07');

        $I->seeResponseContainsJson(['weekIndex' => 2, 'weekStart' => '2026-10-05', 'weekEnd' => '2026-10-11', 'monthStart' => '2026-10-01', 'prevWeekStart' => '2026-10-01', 'nextWeekStart' => '2026-10-12', 'isCurrentWeek' => false]);

        // 63 of 70 → 90 %: not red (red is below 90), not green (from 100).
        $I->assertSame(['plan' => '70', 'fact' => '63', 'completionPct' => '90', 'color' => 'neutral'], $rows['t_sum']['week']);
        // Month to date 10 + 63 = 73 of 310; run rate 73 / 11 days × 31.
        $I->assertSame(['plan' => '310', 'fact' => '73', 'completionPct' => '23.5', 'color' => 'red'], $rows['t_sum']['month']);
        $I->assertSame('205.7273', $rows['t_sum']['runRate']);
        $I->assertSame('66.4', $rows['t_sum']['runRateCompletionPct']);
        // Pace on the same 11 days: 73 / 11 so far; (310 − 73) / 20 remaining days needed to finish on plan.
        $I->assertSame(['currentPerDay' => '6.6364', 'requiredPerDay' => '11.85', 'remainingDays' => 20, 'planReached' => false], $rows['t_sum']['pace']);
        $I->assertNull($rows['t_ratio']['pace'], 'ratios have no daily pace');

        // The next week has no fact yet: a manual metric is extrapolated from the weeks entered (the same
        // 11 days), not from 18 days of which 7 only wait for their numbers.
        $next = $this->rnp($I, 'head', 'dept', '2026-10-12');
        $I->assertNull($next['t_sum']['week']['fact']);
        $I->assertSame('205.7273', $next['t_sum']['runRate']);
        $I->assertSame(20, $next['t_sum']['pace']['remainingDays']);
        $I->assertSame('import', $rows['t_sum']['factOrigin']);
        $I->assertTrue($rows['t_sum']['canEnterFact']);
        $I->assertTrue($rows['t_sum']['canSetPlan']);
    }

    public function ratiosAreRecomputedNotAveraged(FunctionalTester $I): void
    {
        $rows = $this->rnp($I, 'head', 'dept', '2026-10-05');

        $I->assertSame(['plan' => '0.25', 'fact' => '0.25', 'completionPct' => '100', 'color' => 'green'], $rows['t_ratio']['week']);
        // (5 + 30) / (10 + 120), not the average of 0.5 and 0.25.
        $I->assertSame('0.2692', $rows['t_ratio']['month']['fact']);
        $I->assertTrue($rows['t_ratio']['isFormula']);
        $I->assertFalse($rows['t_ratio']['canEnterFact']);
        $I->assertNull($rows['t_ratio']['runRate']);
    }

    public function monthlyLowerBetterAndProratedPlans(FunctionalTester $I): void
    {
        $rows = $this->rnp($I, 'head', 'dept', '2026-10-05');

        $I->assertNull($rows['t_days']['week']);
        $I->assertSame(['plan' => '5', 'fact' => '4', 'completionPct' => '125', 'color' => 'green'], $rows['t_days']['month']);
        // Month plan 310 spread over a 7-day segment of a 31-day month.
        $I->assertSame('70', $rows['t_prorated']['week']['plan']);
        $I->assertNull($rows['t_prorated']['week']['fact']);
        $I->assertNull($rows['t_prorated']['week']['completionPct']);
    }

    public function rollUpSumsChildMetrics(FunctionalTester $I): void
    {
        $rows = $this->rnp($I, 'head', 'company', '2026-10-05', 404);
        $I->assertSame([], $rows, 'a department head does not see the company node');

        $user = $I->haveUser('ceo@rnp.local');
        $em = $I->grabEntityManager();
        $company = $em->find(OrgUnit::class, $this->id['company']);
        $I->assertNotNull($company);
        $em->persist(new OrgMembership($user, $company, OrgRole::Head));
        $em->flush();

        $rows = $this->rnp($I, 'ceo', 'company', '2026-10-05');
        $I->assertSame(['plan' => '70', 'fact' => '63', 'completionPct' => '90', 'color' => 'neutral'], $rows['company_sum']['week']);
        $I->assertTrue($rows['company_sum']['isRollup']);
    }

    public function headEntersFactAndPlan(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@rnp.local');

        $I->sendPut('/api/metrics/'.$this->id['t_sum'].'/facts', ['periodType' => 'week', 'periodStart' => '2026-10-12', 'value' => '12']);
        $I->seeResponseCodeIs(200);
        $I->sendPut('/api/metrics/'.$this->id['t_sum'].'/plans', ['periodType' => 'week', 'periodStart' => '2026-10-12', 'value' => '80']);
        $I->seeResponseCodeIs(200);
        $I->sendPut('/api/metrics/'.$this->id['t_pct'].'/facts', ['periodType' => 'week', 'periodStart' => '2026-10-12', 'value' => '0.45']);
        $I->seeResponseCodeIs(200);
        $I->sendPut('/api/metrics/'.$this->id['t_bool'].'/facts', ['periodType' => 'month', 'periodStart' => '2026-10-01', 'value' => 1]);
        $I->seeResponseCodeIs(200);

        $rows = $this->rnp($I, null, 'dept', '2026-10-12');
        $I->assertSame(['plan' => '80', 'fact' => '12', 'completionPct' => '15', 'color' => 'red'], $rows['t_sum']['week']);
        $I->assertSame('manual', $rows['t_sum']['factOrigin']);
        $I->assertSame('0.45', $rows['t_pct']['week']['fact']);
        $I->assertSame('1', $rows['t_bool']['month']['fact']);

        $I->sendPut('/api/metrics/'.$this->id['t_sum'].'/facts', ['periodType' => 'week', 'periodStart' => '2026-10-12', 'value' => null]);
        $I->seeResponseCodeIs(200);
        $rows = $this->rnp($I, null, 'dept', '2026-10-12');
        $I->assertNull($rows['t_sum']['week']['fact']);
    }

    public function inputIsStrictlyTyped(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@rnp.local');

        foreach ([
            ['t_sum', 'week', '2026-10-12', '12.5', 'целое число'],
            ['t_sum', 'week', '2026-10-12', '€35 000', 'Неверное значение'],
            ['t_sum', 'week', '2026-10-12', '-1', 'вне диапазона'],
            ['t_pct', 'week', '2026-10-12', '45', 'вне диапазона'],
            ['t_pct', 'week', '2026-10-12', '🔴 : 48%', 'Неверное значение'],
            ['t_bool', 'month', '2026-10-01', '2', 'вне диапазона'],
            ['t_sum', 'week', '2026-10-13', '1', 'first day of a week segment'],
            ['t_days', 'week', '2026-10-05', '1', 'month'],
            ['t_ratio', 'week', '2026-10-05', '0.3', 'автоматически'],
            ['t_auto', 'week', '2026-10-05', '100', 'автоматически'],
        ] as [$code, $type, $start, $value, $message]) {
            $I->sendPut('/api/metrics/'.$this->id[$code].'/facts', ['periodType' => $type, 'periodStart' => $start, 'value' => $value]);
            $I->seeResponseCodeIs(422);
            $I->seeResponseContains($message);
        }
    }

    public function rolesLimitWhoMayEnterWhat(FunctionalTester $I): void
    {
        $fact = ['periodType' => 'week', 'periodStart' => '2026-10-12', 'value' => '5'];

        $I->amLoggedInAs('member@rnp.local');
        $I->sendPut('/api/metrics/'.$this->id['t_sum'].'/facts', $fact);
        $I->seeResponseCodeIs(200);
        $I->sendPut('/api/metrics/'.$this->id['t_sum'].'/plans', $fact);
        $I->seeResponseCodeIs(403);

        $I->amLoggedInAs('viewer@rnp.local');
        $I->sendPut('/api/metrics/'.$this->id['t_sum'].'/facts', $fact);
        $I->seeResponseCodeIs(403);
        $I->sendPut('/api/metrics/'.$this->id['t_sum'].'/plans', $fact);
        $I->seeResponseCodeIs(403);
        $rows = $this->rnp($I, null, 'dept', '2026-10-12');
        $I->assertFalse($rows['t_sum']['canEnterFact']);
        $I->assertFalse($rows['t_sum']['canSetPlan']);

        $I->amLoggedInAs('head@rnp.local');
        $I->sendPut('/api/metrics/'.$this->id['other_metric'].'/facts', $fact);
        $I->seeResponseCodeIs(404);
        $I->sendGet('/api/metrics/'.$this->id['other_metric'].'/series');
        $I->seeResponseCodeIs(404);
    }

    public function seriesForTheChart(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@rnp.local');

        $I->sendGet('/api/metrics/'.$this->id['t_sum'].'/series', ['period' => 'week', 'from' => '2026-10-01', 'to' => '2026-10-31']);
        $I->seeResponseCodeIs(200);
        $points = json_decode($I->grabResponse(), true);
        $I->assertCount(5, $points);
        $I->assertSame(['periodStart' => '2026-10-05', 'periodEnd' => '2026-10-11', 'plan' => '70', 'fact' => '63', 'completionPct' => '90', 'color' => 'neutral', 'factPrevYear' => null], $points[1]);

        $I->sendGet('/api/metrics/'.$this->id['t_ratio'].'/series', ['period' => 'month', 'from' => '2026-09-01', 'to' => '2026-10-31']);
        $points = json_decode($I->grabResponse(), true);
        $I->assertSame([null, '0.2692'], array_column($points, 'fact'));

        $I->sendGet('/api/metrics/'.$this->id['t_sum'].'/series', ['period' => 'day']);
        $I->seeResponseCodeIs(400);
        $I->sendGet('/api/metrics/'.$this->id['t_sum'].'/series', ['from' => '2020-01-01', 'to' => '2026-10-31']);
        $I->seeResponseCodeIs(400);
    }

    public function seriesComparesWithTheSamePeriodAYearEarlier(FunctionalTester $I): void
    {
        $em = $I->grabEntityManager();
        $sum = $em->find(Metric::class, $this->id['t_sum']);
        \assert(null !== $sum);
        // 2025: Oct 1 is a Wednesday, so the first segment is 01–05.10.2025.
        $em->persist(new MetricValue($sum, PeriodType::Week, new \DateTimeImmutable('2025-10-01'), self::num('40'), ValueOrigin::Import));
        $em->persist(new MetricValue($sum, PeriodType::Week, new \DateTimeImmutable('2025-10-06'), self::num('7'), ValueOrigin::Import));
        $em->flush();
        $I->amLoggedInAs('head@rnp.local');

        $I->sendGet('/api/metrics/'.$this->id['t_sum'].'/series', ['period' => 'week', 'from' => '2026-10-01', 'to' => '2026-10-31', 'compareYear' => 'true']);
        $points = json_decode($I->grabResponse(), true);
        // 01.10.2026 → 01.10.2025 (segment 01–05.10); 05.10.2026 → 05.10.2025, the same segment; 12.10.2026 → 12.10.2025 (06–12.10).
        $I->assertSame(['40', '40', '7', null, null], array_column($points, 'factPrevYear'));

        $I->sendGet('/api/metrics/'.$this->id['t_sum'].'/series', ['period' => 'month', 'from' => '2026-10-01', 'to' => '2026-10-31', 'compareYear' => 'true']);
        $points = json_decode($I->grabResponse(), true);
        $I->assertSame(['47'], array_column($points, 'factPrevYear'));
    }

    public function planFormulaFollowsTheFactsOfTheSamePeriod(FunctionalTester $I): void
    {
        $em = $I->grabEntityManager();
        $dept = $em->find(OrgUnit::class, $this->id['dept']);
        \assert(null !== $dept);
        $define = function (string $code, MetricUnit $type, ?string $formula = null, ?string $planFormula = null) use ($em, $dept): Metric {
            $m = (new Metric($dept, $code, $code, $type))
                ->setSourceType(MetricSourceType::PlatformApi)
                ->setTimeAgg(null === $formula ? TimeAggregation::Sum : TimeAggregation::Formula)
                ->setFormula($formula)
                ->setPlanFormula($planFormula)
                ->setSort(100 + \count($this->id));
            $em->persist($m);
            $em->flush();
            $this->id[$code] = (int) $m->getId();

            return $m;
        };
        $in = $define('p_incoming', MetricUnit::Eur);
        $direct = $define('p_direct', MetricUnit::Eur, null, 'p_share.plan * p_incoming');
        $share = $define('p_share', MetricUnit::Percent, 'p_direct / p_incoming');
        foreach (['2026-10-01' => ['100', '20'], '2026-10-05' => ['200', '66']] as $start => [$incoming, $directFact]) {
            $em->persist(new MetricValue($in, PeriodType::Week, new \DateTimeImmutable($start), self::num($incoming), ValueOrigin::Auto));
            $em->persist(new MetricValue($direct, PeriodType::Week, new \DateTimeImmutable($start), self::num($directFact), ValueOrigin::Auto));
        }
        $em->persist(new MetricPlan($share, PeriodType::Month, new \DateTimeImmutable('2026-10-01'), self::num('0.3')));
        // A month planned before the formula existed keeps its plan.
        $em->persist(new MetricPlan($direct, PeriodType::Month, new \DateTimeImmutable('2026-09-01'), self::num('900')));
        $em->flush();

        $rows = $this->rnp($I, 'head', 'dept', '2026-10-05');
        // 30 % of this week's 200 incoming; the month plan follows the month to date: 30 % of 100 + 200.
        $I->assertSame(['plan' => '60', 'fact' => '66', 'completionPct' => '110', 'color' => 'green'], $rows['p_direct']['week']);
        $I->assertSame(['plan' => '90', 'fact' => '86', 'completionPct' => '95.6', 'color' => 'neutral'], $rows['p_direct']['month']);
        $I->assertSame('p_share.plan * p_incoming', $rows['p_direct']['planFormula']);
        $I->assertNull($rows['p_direct']['runRate'], 'no run rate against a moving plan');
        $I->assertNull($rows['p_direct']['pace']);
        $I->assertTrue($rows['p_direct']['canSetPlan'], 'a head may override the formula');
        // The ratio keeps its month target every week.
        $I->assertSame(['plan' => '0.3', 'fact' => '0.33', 'completionPct' => '110', 'color' => 'green'], $rows['p_share']['week']);

        // A set plan overrides the formula for its period; removing it brings the formula back.
        $I->sendPut('/api/metrics/'.$this->id['p_direct'].'/plans', ['periodType' => 'month', 'periodStart' => '2026-10-01', 'value' => '100']);
        $I->seeResponseCodeIs(200);
        $overridden = $this->rnp($I, null, 'dept', '2026-10-05')['p_direct'];
        $I->assertSame('100', $overridden['month']['plan']);
        $I->assertNull($overridden['planFormula'], 'the plan of that month is not computed');
        $I->assertNotNull($overridden['runRate'], 'an ordinary plan has a run rate');
        $I->sendPut('/api/metrics/'.$this->id['p_direct'].'/plans', ['periodType' => 'month', 'periodStart' => '2026-10-01', 'value' => null]);
        $I->assertSame('90', $this->rnp($I, null, 'dept', '2026-10-05')['p_direct']['month']['plan']);

        // Overriding one week keeps the month on the formula (a single week plan is not the month's plan).
        $I->sendPut('/api/metrics/'.$this->id['p_direct'].'/plans', ['periodType' => 'week', 'periodStart' => '2026-10-05', 'value' => '500']);
        $I->seeResponseCodeIs(200);
        $weekOverride = $this->rnp($I, null, 'dept', '2026-10-05')['p_direct'];
        $I->assertSame('500', $weekOverride['week']['plan']);
        $I->assertSame('90', $weekOverride['month']['plan']);
        $I->assertSame('p_share.plan * p_incoming', $weekOverride['planFormula']);
        $I->sendPut('/api/metrics/'.$this->id['p_direct'].'/plans', ['periodType' => 'week', 'periodStart' => '2026-10-05', 'value' => null]);

        // Without the share target (November) there is no plan, and no error.
        $rows = $this->rnp($I, null, 'dept', '2026-11-02');
        $I->assertNull($rows['p_direct']['week']['plan']);
        $I->assertNull($rows['p_direct']['month']['plan']);

        $I->sendGet('/api/metrics/'.$this->id['p_direct'].'/series', ['period' => 'month', 'from' => '2026-09-01', 'to' => '2026-10-31']);
        $points = json_decode($I->grabResponse(), true);
        $I->assertSame(['900', '90'], array_column($points, 'plan'));
    }

    private static function num(string $value): Number
    {
        \assert(is_numeric($value));

        return new Number($value);
    }

    public function dailyAverageIsShownForMetricsThatAskForIt(FunctionalTester $I): void
    {
        $em = $I->grabEntityManager();
        $em->find(Metric::class, $this->id['t_sum'])?->setShowDailyAvg(true);
        // Ratios never get one, whatever the flag says.
        $em->find(Metric::class, $this->id['t_ratio'])?->setShowDailyAvg(true);
        $em->flush();

        // 05–11.10: 63 over 7 days; month to date 73 over 11 days.
        $rows = $this->rnp($I, 'head', 'dept', '2026-10-07');
        $I->assertSame(['week' => '9', 'month' => '6.6'], $rows['t_sum']['dailyAverage']);
        $I->assertNull($rows['t_num']['dailyAverage'], 'only metrics with the flag');
        $I->assertNull($rows['t_ratio']['dailyAverage']);

        // 01–04.10 is a four-day segment: 10 over 4 days.
        $rows = $this->rnp($I, null, 'dept', '2026-10-01');
        $I->assertSame(['week' => '2.5', 'month' => '2.5'], $rows['t_sum']['dailyAverage']);

        // A week without a fact: no weekly average, the month keeps the days entered so far.
        $rows = $this->rnp($I, null, 'dept', '2026-10-12');
        $I->assertSame(['week' => null, 'month' => '6.6'], $rows['t_sum']['dailyAverage']);
    }

    /**
     * @return array<string, array<string, mixed>> rows keyed by metric code
     */
    private function rnp(FunctionalTester $I, ?string $as, string $unit, string $week, int $status = 200): array
    {
        if (null !== $as) {
            $I->amLoggedInAs($as.'@rnp.local');
        }
        $I->sendGet('/api/org-units/'.$this->id[$unit].'/rnp', ['week' => $week]);
        $I->seeResponseCodeIs($status);
        if (200 !== $status) {
            return [];
        }

        /** @var array{rows: list<array<string, mixed>>} $data */
        $data = json_decode($I->grabResponse(), true);

        return array_column($data['rows'], null, 'code');
    }
}
