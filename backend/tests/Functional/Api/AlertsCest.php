<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Action\BaseFunction;
use App\Entity\Journal\ErrorEntry;
use App\Entity\Metric\Metric;
use App\Entity\Metric\MetricPlan;
use App\Entity\Metric\MetricValue;
use App\Entity\Org\OrgMembership;
use App\Enum\ErrorImpactType;
use App\Enum\MetricUnit;
use App\Enum\OrgRole;
use App\Enum\Periodicity;
use App\Enum\PeriodType;
use App\Enum\ValueOrigin;
use App\Service\Org\OrgTreeManager;
use App\Service\Period\PeriodCalendar;
use App\Tests\Support\FunctionalTester;
use BcMath\Number;

/**
 * Bell alerts, relative to today. Dept: head, member, viewer.
 * Last finished week: t_red planned 100 with fact 10 (red), t_ok on plan, t_missing without a fact.
 * A weekly base function owned by the member is never checked; the member reported an error.
 */
final class AlertsCest
{
    private string $lastWeek = '';

    private int $deptId = 0;

    public function _before(FunctionalTester $I): void
    {
        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $company = $tree->createUnit('Company');
        $dept = $tree->createUnit('Dept', $company);
        $em = $I->grabEntityManager();

        $users = [];
        foreach (['head' => OrgRole::Head, 'member' => OrgRole::Member, 'viewer' => OrgRole::Viewer] as $name => $role) {
            $users[$name] = $I->haveUser($name.'@rnp.local', ucfirst($name));
            $em->persist(new OrgMembership($users[$name], $dept, $role));
        }

        /** @var PeriodCalendar $calendar */
        $calendar = $I->grabService(PeriodCalendar::class);
        $current = $calendar->weekSegmentFor(new \DateTimeImmutable('today'));
        $last = $calendar->weekSegmentFor($current->start->modify('-1 day'))->start;
        $this->lastWeek = $last->format('Y-m-d');

        foreach (['t_red' => ['100', '10'], 't_ok' => ['10', '10'], 't_missing' => ['10', null]] as $code => [$plan, $fact]) {
            $metric = new Metric($dept, $code, $code, MetricUnit::Count);
            $em->persist($metric);
            $em->persist(new MetricPlan($metric, PeriodType::Week, $last, new Number($plan)));
            if (null !== $fact) {
                $em->persist(new MetricValue($metric, PeriodType::Week, $last, new Number($fact), ValueOrigin::Import));
            }
        }

        $em->persist((new BaseFunction($dept, 'Weekly report', Periodicity::Weekly))->setOwnerUser($users['member']));
        $em->persist(new ErrorEntry($dept, new \DateTimeImmutable('today'), 'Сломался импорт цен', ErrorImpactType::Other, $users['member']));
        $em->flush();
        $this->deptId = (int) $dept->getId();
    }

    public function headGetsRedMetricsMissingFactsMissedActionsAndNewErrors(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@rnp.local');
        $alerts = $this->alerts($I);

        $I->assertSame(['t_red'], $alerts['metric_red']['metrics']);
        $I->assertSame($this->lastWeek, $alerts['metric_red']['week']);
        $I->assertSame($this->deptId, $alerts['metric_red']['orgUnitId']);
        $I->assertSame(['t_missing'], $alerts['facts_missing']['metrics']);
        $I->assertGreaterThanOrEqual(1, $alerts['actions_missed']['count']);
        $I->assertSame(1, $alerts['errors_new']['count']);
    }

    public function memberGetsOwnWorkOnlyAndViewerNothing(FunctionalTester $I): void
    {
        $I->amLoggedInAs('member@rnp.local');
        $alerts = $this->alerts($I);
        // No red metrics or run rate for members; the error is their own report.
        $I->assertSame(['facts_missing', 'actions_missed'], array_keys($alerts));

        $I->amLoggedInAs('viewer@rnp.local');
        $I->assertSame([], $this->alerts($I));
    }

    public function enteredFactClearsTheAlert(FunctionalTester $I): void
    {
        $I->amLoggedInAs('member@rnp.local');
        $alerts = $this->alerts($I);
        $I->sendPut('/api/metrics/'.$this->metricId($I, 't_missing').'/facts', ['periodType' => 'week', 'periodStart' => $this->lastWeek, 'value' => '0']);
        $I->seeResponseCodeIs(200);
        $I->assertArrayHasKey('facts_missing', $alerts);
        $I->assertArrayNotHasKey('facts_missing', $this->alerts($I));
    }

    /**
     * @return array<string, array<string, mixed>> alerts by type (call exclusions are not part of this scenario)
     */
    private function alerts(FunctionalTester $I): array
    {
        $I->sendGet('/api/notifications');
        $I->seeResponseCodeIs(200);
        /** @var list<array<string, mixed>> $items */
        $items = json_decode($I->grabResponse(), true);
        $byType = [];
        foreach ($items as $item) {
            $byType[(string) $item['type']] = $item;
        }

        return $byType;
    }

    private function metricId(FunctionalTester $I, string $code): int
    {
        return (int) $I->grabEntityManager()->getRepository(Metric::class)->findOneBy(['code' => $code])?->getId();
    }
}
