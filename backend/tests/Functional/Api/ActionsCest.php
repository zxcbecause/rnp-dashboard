<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Metric\Metric;
use App\Entity\Org\OrgMembership;
use App\Enum\MetricUnit;
use App\Enum\OrgRole;
use App\Service\Org\OrgTreeManager;
use App\Tests\Support\FunctionalTester;

/**
 * Week 26.10–31.10.2026 (the last October segment, in the past): daily D, weekly W, monthly M
 * base functions and roadmap items of Dept's metric.
 */
final class ActionsCest
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

        foreach (['metric' => $dept, 'foreign' => $other] as $key => $unit) {
            $metric = new Metric($unit, 'a_'.$key, 'Metric '.$key, MetricUnit::Count);
            $em->persist($metric);
            $em->flush();
            $this->id[$key] = (int) $metric->getId();
        }
        $this->id['dept'] = (int) $dept->getId();
        $this->id['other'] = (int) $other->getId();

        foreach (['head' => OrgRole::Head, 'member' => OrgRole::Member, 'viewer' => OrgRole::Viewer] as $name => $role) {
            $user = $I->haveUser($name.'@act.local', ucfirst($name));
            $em->persist(new OrgMembership($user, $dept, $role));
            $this->id[$name] = (int) $user->getId();
        }
        $outsider = $I->haveUser('outsider@act.local');
        $em->persist(new OrgMembership($outsider, $other, OrgRole::Member));
        $this->id['outsider'] = (int) $outsider->getId();
        $em->flush();
    }

    public function headBuildsTheWeekAndExecutionRateIsCounted(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@act.local');
        $d = $this->createFunction($I, ['title' => 'D', 'periodicity' => 'daily']);
        $w = $this->createFunction($I, ['title' => 'W', 'periodicity' => 'weekly', 'ownerUserId' => $this->id['member'], 'metricId' => $this->id['metric']]);
        $m = $this->createFunction($I, ['title' => 'M', 'periodicity' => 'monthly', 'dueText' => 'до 20 числа']);

        foreach ([[$d, '2026-10-26', true], [$d, '2026-10-27', true], [$d, '2026-10-28', false], [$w, '2026-10-26', true], [$m, '2026-10-01', true]] as [$fn, $day, $done]) {
            $I->sendPut("/api/base-functions/$fn/checks", ['periodStart' => $day, 'done' => $done, 'note' => 'ok']);
            $I->seeResponseCodeIs(204);
        }

        $done = $this->createItem($I, ['title' => 'Done item', 'dueDate' => '2026-10-28', 'status' => 'done']);
        $this->createItem($I, ['title' => 'Late item', 'dueDate' => '2026-10-30', 'status' => 'in_progress', 'ownerUserId' => $this->id['member']]);
        $this->createItem($I, ['title' => 'Dropped', 'dueDate' => '2026-10-29', 'status' => 'cancelled']);

        $week = $this->week($I, '2026-10-28');
        $I->assertSame(['2026-10-26', '2026-10-31', '2026-10-01'], [$week['weekStart'], $week['weekEnd'], $week['monthStart']]);
        $I->assertTrue($week['canManage']);

        $functions = array_column($week['baseFunctions'], null, 'title');
        $I->assertSame(['done', 'done', 'not_done', 'missed', 'missed'], array_column($functions['D']['slots'], 'state'));
        $I->assertSame(['2026-10-26'], array_column($functions['W']['slots'], 'periodStart'));
        $I->assertSame(['Metric metric', 'Member'], [$functions['W']['metric']['name'], $functions['W']['owner']['name']]);
        $I->assertSame(['2026-10-01', '2026-10-31', 'done', 'до 20 числа'], [$functions['M']['slots'][0]['periodStart'], $functions['M']['slots'][0]['due'], $functions['M']['slots'][0]['state'], $functions['M']['dueText']]);

        // Week: D 2 of 5, W 1 of 1, M 1 of 1 (due 31.10), roadmap 1 of 2 (cancelled is not counted) → 5 of 9.
        $I->assertSame(['planned' => 9, 'done' => 5, 'pct' => '55.6', 'baseFunctionsPlanned' => 7, 'baseFunctionsDone' => 4, 'roadmapPlanned' => 2, 'roadmapDone' => 1, 'sprintPlanned' => 0, 'sprintDone' => 0], $week['weekExecution']);
        // Month: D 2 of 22 working days, W 1 of 5 segments, M 1 of 1, roadmap 1 of 2 → 5 of 30.
        $I->assertSame(['planned' => 30, 'done' => 5, 'pct' => '16.7'], array_intersect_key($week['monthExecution'], ['planned' => 0, 'done' => 0, 'pct' => 0]));

        $roadmap = array_column($week['roadmap'], null, 'title');
        $I->assertSame(['Late item', 'Done item', 'Dropped'], array_column($week['roadmap'], 'title'), 'open first, then done, then cancelled');
        $I->assertTrue($roadmap['Late item']['isOverdue']);
        $I->assertFalse($roadmap['Done item']['isOverdue']);
        $I->assertContains(['kind' => 'roadmap', 'id' => $roadmap['Late item']['id'], 'title' => 'Late item', 'periodStart' => '2026-10-30', 'due' => '2026-10-30', 'owner' => ['id' => $this->id['member'], 'name' => 'Member']], $week['missed']);
        $I->assertSame(['status_changed', 'created'], array_column($roadmap['Done item']['events'], 'type'));

        $I->assertSame(['Head', 'Member', 'Viewer'], array_column($week['people'], 'name'));
        $I->assertSame([$this->id['metric']], array_column($week['metrics'], 'id'));
        $I->assertSame($done, $roadmap['Done item']['id']);
    }

    public function checksAreValidatedByPeriod(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@act.local');
        $d = $this->createFunction($I, ['title' => 'D', 'periodicity' => 'daily']);
        $w = $this->createFunction($I, ['title' => 'W', 'periodicity' => 'weekly']);

        foreach ([[$d, '2026-10-31', 'началом периода'], [$w, '2026-10-27', 'началом периода'], [$w, '2099-01-05', 'ещё не начался']] as [$fn, $day, $message]) {
            $I->sendPut("/api/base-functions/$fn/checks", ['periodStart' => $day, 'done' => true]);
            $I->seeResponseCodeIs(422);
            $I->seeResponseContains($message);
        }

        // A cut week starting on Sunday 01.11 is a valid weekly period; done = null removes the mark.
        $I->sendPut("/api/base-functions/$w/checks", ['periodStart' => '2026-11-01', 'done' => true]);
        $I->seeResponseCodeIs(204);
        $I->sendPut("/api/base-functions/$w/checks", ['periodStart' => '2026-11-01', 'done' => null]);
        $I->seeResponseCodeIs(204);
        $week = $this->week($I, '2026-11-01');
        $I->assertNull(array_column($week['baseFunctions'], null, 'title')['W']['slots'][0]['done']);
    }

    public function rolesLimitActions(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@act.local');
        $fn = $this->createFunction($I, ['title' => 'W', 'periodicity' => 'weekly']);
        $item = $this->createItem($I, ['title' => 'Item', 'dueDate' => '2026-10-28', 'ownerUserId' => $this->id['member']]);

        // Member: checks yes, managing no; as the item owner may move its status only.
        $I->amLoggedInAs('member@act.local');
        $I->sendPut("/api/base-functions/$fn/checks", ['periodStart' => '2026-10-26', 'done' => true]);
        $I->seeResponseCodeIs(204);
        $I->sendPost('/api/org-units/'.$this->id['dept'].'/base-functions', ['title' => 'X', 'periodicity' => 'daily']);
        $I->seeResponseCodeIs(403);
        $I->sendPut("/api/roadmap-items/$item", ['title' => 'Item', 'dueDate' => '2026-10-28', 'ownerUserId' => $this->id['member'], 'status' => 'in_progress']);
        $I->seeResponseCodeIs(204);
        $I->sendPut("/api/roadmap-items/$item", ['title' => 'Renamed', 'dueDate' => '2026-10-28', 'ownerUserId' => $this->id['member'], 'status' => 'in_progress']);
        $I->seeResponseCodeIs(403);
        $week = $this->week($I, '2026-10-28');
        $I->assertFalse($week['canManage']);
        $I->assertTrue($week['roadmap'][0]['canChangeStatus']);
        $I->assertFalse($week['roadmap'][0]['canEdit']);

        // Viewer: read only.
        $I->amLoggedInAs('viewer@act.local');
        $I->sendPut("/api/base-functions/$fn/checks", ['periodStart' => '2026-10-26', 'done' => false]);
        $I->seeResponseCodeIs(403);
        $I->sendPut("/api/roadmap-items/$item", ['title' => 'Item', 'dueDate' => '2026-10-28', 'ownerUserId' => $this->id['member'], 'status' => 'done']);
        $I->seeResponseCodeIs(403);

        // Outsider: the whole unit does not exist.
        $I->amLoggedInAs('outsider@act.local');
        $I->sendGet('/api/org-units/'.$this->id['dept'].'/actions');
        $I->seeResponseCodeIs(404);
        $I->sendPut("/api/base-functions/$fn/checks", ['periodStart' => '2026-10-26', 'done' => true]);
        $I->seeResponseCodeIs(404);
        $I->sendPut("/api/roadmap-items/$item", ['title' => 'x', 'status' => 'done']);
        $I->seeResponseCodeIs(404);
    }

    public function roadmapHistoryAndEffect(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@act.local');
        $item = $this->createItem($I, ['title' => 'Lookalike', 'dueDate' => '2026-11-20', 'expectedEffect' => 'CPL −15 %']);

        $I->sendPost("/api/roadmap-items/$item/effect", ['effect' => 'affected']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContains('после выполнения');

        $I->sendPut("/api/roadmap-items/$item", ['title' => 'Lookalike audiences', 'dueDate' => '2026-11-25', 'ownerUserId' => $this->id['member'], 'status' => 'done', 'expectedEffect' => 'CPL −15 %']);
        $I->seeResponseCodeIs(204);
        $I->sendPost("/api/roadmap-items/$item/effect", ['effect' => 'not_affected', 'note' => 'CPL не изменился']);
        $I->seeResponseCodeIs(204);

        $events = array_column($this->week($I, '2026-11-02')['roadmap'], null, 'id')[$item]['events'];
        $I->assertSame(['created', 'field_changed', 'field_changed', 'field_changed', 'status_changed', 'effect_assessed'], array_reverse(array_column($events, 'type')));
        $changes = array_map(static fn (array $e): ?string => $e['field'] ? $e['field'].': '.$e['oldValue'].' → '.$e['newValue'] : null, array_reverse($events));
        $I->assertSame([null, 'title: Lookalike → Lookalike audiences', 'owner:  → Member', 'due_date: 2026-11-20 → 2026-11-25', 'status: planned → done', null], $changes);
        $I->assertSame(['not_affected', 'CPL не изменился', 'Head'], [$events[0]['effect'], $events[0]['note'], $events[0]['actor']['name']]);
        $I->assertSame('not_affected', array_column($this->week($I, '2026-11-02')['roadmap'], null, 'id')[$item]['effect']);
    }

    public function invalidLinksAreRejected(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@act.local');

        $I->sendPost('/api/org-units/'.$this->id['dept'].'/base-functions', ['title' => 'X', 'periodicity' => 'weekly', 'metricId' => $this->id['foreign']]);
        $I->seeResponseCodeIs(422);
        $I->sendPost('/api/org-units/'.$this->id['dept'].'/base-functions', ['title' => 'X', 'periodicity' => 'weekly', 'ownerUserId' => $this->id['outsider']]);
        $I->seeResponseCodeIs(422);
        $I->sendPost('/api/org-units/'.$this->id['dept'].'/base-functions', ['title' => '', 'periodicity' => 'yearly']);
        $I->seeResponseCodeIs(422);
        $I->sendPost('/api/metrics/'.$this->id['foreign'].'/roadmap', ['title' => 'X']);
        $I->seeResponseCodeIs(404);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createFunction(FunctionalTester $I, array $body): int
    {
        $I->sendPost('/api/org-units/'.$this->id['dept'].'/base-functions', $body);
        $I->seeResponseCodeIs(201);

        return (int) $I->grabDataFromResponseByJsonPath('$.id')[0];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createItem(FunctionalTester $I, array $body): int
    {
        $I->sendPost('/api/metrics/'.$this->id['metric'].'/roadmap', $body);
        $I->seeResponseCodeIs(201);

        return (int) $I->grabDataFromResponseByJsonPath('$.id')[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function week(FunctionalTester $I, string $week): array
    {
        $I->sendGet('/api/org-units/'.$this->id['dept'].'/actions', ['week' => $week]);
        $I->seeResponseCodeIs(200);

        /** @var array<string, mixed> $data */
        $data = json_decode($I->grabResponse(), true);

        return $data;
    }
}
