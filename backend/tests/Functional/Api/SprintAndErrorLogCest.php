<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Metric\Metric;
use App\Entity\Org\OrgMembership;
use App\Enum\MetricUnit;
use App\Enum\OrgRole;
use App\Service\Org\OrgTreeManager;
use App\Tests\Support\FunctionalTester;

final class SprintAndErrorLogCest
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

        $metric = new Metric($dept, 's_metric', 'Metric', MetricUnit::Count);
        $foreign = new Metric($other, 's_foreign', 'Foreign', MetricUnit::Count);
        $em->persist($metric);
        $em->persist($foreign);
        $em->flush();
        // The Cest instance is reused between tests: reset the ids, never merge them.
        $this->id = ['company' => (int) $company->getId(), 'dept' => (int) $dept->getId(), 'other' => (int) $other->getId(), 'metric' => (int) $metric->getId(), 'foreign' => (int) $foreign->getId()];

        foreach (['head' => OrgRole::Head, 'member' => OrgRole::Member, 'viewer' => OrgRole::Viewer] as $name => $role) {
            $user = $I->haveUser($name.'@sp.local', ucfirst($name));
            $em->persist(new OrgMembership($user, $dept, $role));
            $this->id[$name] = (int) $user->getId();
        }
        $outsider = $I->haveUser('outsider@sp.local');
        $em->persist(new OrgMembership($outsider, $other, OrgRole::Member));
        $this->id['outsider'] = (int) $outsider->getId();
        $em->flush();
    }

    public function sprintWeekIsMondayToSundayAndCountsInExecutionRate(FunctionalTester $I): void
    {
        $I->amLoggedInAs('member@sp.local');
        $done = $this->task($I, ['title' => 'Done', 'priority' => 'P2', 'progressPct' => 100, 'weekStart' => '2026-10-21']);
        $this->task($I, ['title' => 'Half', 'priority' => 'P1', 'progressPct' => 50, 'weekStart' => '2026-10-19', 'metricId' => $this->id['metric'], 'link' => 'https://example.com/x']);

        $week = $this->sprint($I, '2026-10-25');
        $I->assertSame(['2026-10-19', '2026-10-25', '2026-10-12', '2026-10-26'], [$week['weekStart'], $week['weekEnd'], $week['prevWeekStart'], $week['nextWeekStart']]);
        $I->assertSame(['Half', 'Done'], array_column($week['tasks'], 'title'), 'P1 first');
        $I->assertSame([1, 75, 1], [$week['doneCount'], $week['avgProgressPct'], $week['carryableCount']]);
        $I->assertSame('Metric', $week['tasks'][0]['metric']['name']);
        $I->assertTrue($week['canEdit']);
        $I->assertFalse($week['canManageRecurring']);

        // The week ends on Sunday 25.10 → it counts in the RNP segment 19–25.10 and in October.
        $I->sendGet('/api/org-units/'.$this->id['dept'].'/actions', ['week' => '2026-10-19']);
        $actions = json_decode($I->grabResponse(), true);
        $I->assertSame([2, 1], [$actions['weekExecution']['sprintPlanned'], $actions['weekExecution']['sprintDone']]);
        $I->assertSame([2, 1], [$actions['monthExecution']['sprintPlanned'], $actions['monthExecution']['sprintDone']]);

        $I->sendPut("/api/sprint-tasks/$done", ['title' => 'Done', 'priority' => 'P2', 'progressPct' => 90, 'resultNote' => 'почти']);
        $I->seeResponseCodeIs(204);
        $I->assertSame(0, $this->sprint($I, '2026-10-19')['doneCount']);
    }

    public function carryOverCopiesUnfinishedTasksOnce(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@sp.local');
        $this->task($I, ['title' => 'Unfinished', 'progressPct' => 40, 'tag' => 'ops', 'nextStep' => 'позвонить', 'weekStart' => '2026-10-19']);
        $this->task($I, ['title' => 'Finished', 'progressPct' => 100, 'weekStart' => '2026-10-19']);

        $I->sendPost('/api/org-units/'.$this->id['dept'].'/sprint/carry-over?week=2026-10-19');
        $I->seeResponseContainsJson(['count' => 1]);
        $I->sendPost('/api/org-units/'.$this->id['dept'].'/sprint/carry-over?week=2026-10-19');
        $I->seeResponseContainsJson(['count' => 0]);

        $next = $this->sprint($I, '2026-10-26');
        $I->assertCount(1, $next['tasks']);
        $I->assertSame(['Unfinished', 40, 'ops', 'позвонить', '2026-10-19'], [$next['tasks'][0]['title'], $next['tasks'][0]['progressPct'], $next['tasks'][0]['tag'], $next['tasks'][0]['nextStep'], $next['tasks'][0]['carriedFromWeek']]);
        $I->assertSame(0, $this->sprint($I, '2026-10-19')['carryableCount']);
    }

    public function recurringTasksArePulledByPeriodicity(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@sp.local');
        foreach ([['Еженедельная', 'weekly'], ['Ежемесячная', 'monthly'], ['Ежеквартальная', 'quarterly'], ['Выключенная', 'weekly']] as [$title, $periodicity]) {
            $I->sendPost('/api/org-units/'.$this->id['dept'].'/recurring-tasks', ['title' => $title, 'periodicity' => $periodicity, 'isActive' => 'Выключенная' !== $title]);
            $I->seeResponseCodeIs(201);
        }

        // 05.10.2026 is the first Monday of October and of Q4; 02.11 the first Monday of November.
        $pulled = fn (string $week): array => $this->pull($I, $week);
        $I->assertSame(['Еженедельная', 'Ежемесячная', 'Ежеквартальная'], $pulled('2026-10-05'));
        $I->assertSame(['Еженедельная'], $pulled('2026-10-12'));
        $I->assertSame(['Еженедельная', 'Ежемесячная'], $pulled('2026-11-02'));

        // Pulling twice does not duplicate.
        $I->sendPost('/api/org-units/'.$this->id['dept'].'/sprint/pull-recurring?week=2026-11-02');
        $I->seeResponseContainsJson(['count' => 0]);
        $I->assertCount(4, $this->sprint($I, '2026-11-02')['recurring']);

        $I->amLoggedInAs('member@sp.local');
        $I->sendPost('/api/org-units/'.$this->id['dept'].'/recurring-tasks', ['title' => 'X', 'periodicity' => 'weekly']);
        $I->seeResponseCodeIs(403);
    }

    public function sprintRolesAndValidation(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@sp.local');
        $task = $this->task($I, ['title' => 'T', 'weekStart' => '2026-10-19']);

        foreach ([
            ['title' => 'X', 'progressPct' => 120, 'weekStart' => '2026-10-19'],
            ['title' => 'X', 'priority' => 'P9', 'weekStart' => '2026-10-19'],
            ['title' => 'X', 'link' => 'not a link', 'weekStart' => '2026-10-19'],
            ['title' => 'X', 'metricId' => $this->id['foreign'], 'weekStart' => '2026-10-19'],
            ['title' => 'X'],
        ] as $body) {
            $I->sendPost('/api/org-units/'.$this->id['dept'].'/sprint-tasks', $body);
            $I->seeResponseCodeIs(422);
        }

        $I->amLoggedInAs('viewer@sp.local');
        $I->assertFalse($this->sprint($I, '2026-10-19')['canEdit']);
        $I->sendPut("/api/sprint-tasks/$task", ['title' => 'T', 'progressPct' => 10]);
        $I->seeResponseCodeIs(403);
        $I->sendDelete("/api/sprint-tasks/$task");
        $I->seeResponseCodeIs(403);

        $I->amLoggedInAs('outsider@sp.local');
        $I->sendGet('/api/org-units/'.$this->id['dept'].'/sprint');
        $I->seeResponseCodeIs(404);
        $I->sendPut("/api/sprint-tasks/$task", ['title' => 'T']);
        $I->seeResponseCodeIs(404);

        $I->amLoggedInAs('member@sp.local');
        $I->sendDelete("/api/sprint-tasks/$task");
        $I->seeResponseCodeIs(204);
        $I->assertSame([], $this->sprint($I, '2026-10-19')['tasks']);
    }

    public function errorLogKeepsOnlySignificantMistakes(FunctionalTester $I): void
    {
        $I->amLoggedInAs('member@sp.local');
        $money = $this->error($I, ['occurredOn' => '2026-10-10', 'description' => 'Двойная выплата собственнику', 'impactType' => 'money', 'impactAmount' => '1250.50', 'ownerUserId' => $this->id['head']]);
        $this->error($I, ['occurredOn' => '2026-10-20', 'description' => 'Гость не смог заселиться: код не отправлен', 'impactType' => 'client', 'status' => 'resolved']);

        foreach ([
            [['description' => 'коротко', 'impactType' => 'other'], 'подробнее'],
            [['description' => 'Потеря денег без суммы', 'impactType' => 'money'], 'сумму ущерба'],
            [['description' => 'Потеря денег с мусором', 'impactType' => 'money', 'impactAmount' => '€35 000'], 'Сумма ущерба'],
            [['description' => 'Ошибка из будущего', 'impactType' => 'other', 'occurredOn' => '2099-01-01'], 'в будущем'],
            [['description' => 'Чужой ответственный', 'impactType' => 'other', 'ownerUserId' => $this->id['outsider']], 'Ответственный'],
        ] as [$body, $message]) {
            $I->sendPost('/api/org-units/'.$this->id['dept'].'/errors', $body + ['occurredOn' => '2026-10-11']);
            $I->seeResponseCodeIs(422);
            $I->seeResponseContains($message);
        }

        $I->sendGet('/api/org-units/'.$this->id['dept'].'/errors', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $log = json_decode($I->grabResponse(), true);
        $I->assertSame([2, 1, '1250.5'], [$log['total'], $log['open'], $log['moneyTotal']]);
        $I->assertSame(['money' => 1, 'blocked' => 0, 'client' => 1, 'other' => 0], $log['byImpact']);
        $I->assertSame(['2026-10-20', '2026-10-10'], array_column($log['entries'], 'occurredOn'), 'newest first');
        $I->assertSame(['Member', 'Head', '1250.5'], [$log['entries'][1]['reporter']['name'], $log['entries'][1]['owner']['name'], $log['entries'][1]['impactAmount']]);

        // The head closes it with cause and prevention; a viewer can neither report nor edit.
        $I->amLoggedInAs('head@sp.local');
        $I->sendPut("/api/errors/$money", ['occurredOn' => '2026-10-10', 'description' => 'Двойная выплата собственнику', 'impactType' => 'money', 'impactAmount' => '1250.50', 'cause' => 'Ручной платёж без сверки', 'prevention' => 'Сверка перед выплатой', 'status' => 'resolved']);
        $I->seeResponseCodeIs(204);

        $I->amLoggedInAs('viewer@sp.local');
        $I->sendGet('/api/org-units/'.$this->id['dept'].'/errors', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $log = json_decode($I->grabResponse(), true);
        $I->assertSame([0, false, false], [$log['open'], $log['canReport'], $log['entries'][0]['canEdit']]);
        $I->sendPost('/api/org-units/'.$this->id['dept'].'/errors', ['occurredOn' => '2026-10-11', 'description' => 'Наблюдатель пытается', 'impactType' => 'other']);
        $I->seeResponseCodeIs(403);
        $I->sendPut("/api/errors/$money", ['occurredOn' => '2026-10-10', 'description' => 'Наблюдатель правит', 'impactType' => 'other']);
        $I->seeResponseCodeIs(403);

        $I->amLoggedInAs('outsider@sp.local');
        $I->sendGet('/api/org-units/'.$this->id['dept'].'/errors');
        $I->seeResponseCodeIs(404);
    }

    public function errorLogOfAUnitIncludesTheVisibleBranch(FunctionalTester $I): void
    {
        $em = $I->grabEntityManager();
        /** @var \App\Entity\Org\OrgUnit $company */
        $company = $em->find(\App\Entity\Org\OrgUnit::class, $this->id['company']);
        $em->persist(new OrgMembership($I->haveUser('ceo@sp.local', 'Ceo'), $company, OrgRole::Head));
        $em->persist(new OrgMembership($I->haveUser('office@sp.local', 'Office'), $company, OrgRole::Member));
        $em->flush();

        $I->amLoggedInAs('member@sp.local');
        $this->error($I, ['occurredOn' => '2026-10-10', 'description' => 'Ошибка отдела Dept', 'impactType' => 'client']);
        $I->amLoggedInAs('outsider@sp.local');
        $I->sendPost('/api/org-units/'.$this->id['other'].'/errors', ['occurredOn' => '2026-10-12', 'description' => 'Ошибка отдела Other', 'impactType' => 'other']);
        $I->seeResponseCodeIs(201);
        $I->amLoggedInAs('office@sp.local');
        $I->sendPost('/api/org-units/'.$this->id['company'].'/errors', ['occurredOn' => '2026-10-14', 'description' => 'Ошибка самой компании', 'impactType' => 'other']);
        $I->seeResponseCodeIs(201);

        $range = ['from' => '2026-10-01', 'to' => '2026-10-31'];
        // The head of the company reads the whole branch, each entry labelled with its department.
        $I->amLoggedInAs('ceo@sp.local');
        $I->sendGet('/api/org-units/'.$this->id['company'].'/errors', $range);
        $log = json_decode($I->grabResponse(), true);
        $I->assertSame(3, $log['total']);
        $I->assertSame(['Company', 'Other', 'Dept'], array_column($log['entries'], 'orgUnitName'));

        // A member of the company sees only the company's own entries; a department head only the department.
        $I->amLoggedInAs('office@sp.local');
        $I->sendGet('/api/org-units/'.$this->id['company'].'/errors', $range);
        $I->assertSame(['Company'], array_column(json_decode($I->grabResponse(), true)['entries'], 'orgUnitName'));
        $I->amLoggedInAs('head@sp.local');
        $I->sendGet('/api/org-units/'.$this->id['dept'].'/errors', $range);
        $I->assertSame(['Dept'], array_column(json_decode($I->grabResponse(), true)['entries'], 'orgUnitName'));
        $I->sendGet('/api/org-units/'.$this->id['company'].'/errors', $range);
        $I->seeResponseCodeIs(404);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function task(FunctionalTester $I, array $body): int
    {
        $I->sendPost('/api/org-units/'.$this->id['dept'].'/sprint-tasks', $body);
        $I->seeResponseCodeIs(201);

        return (int) $I->grabDataFromResponseByJsonPath('$.id')[0];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function error(FunctionalTester $I, array $body): int
    {
        $I->sendPost('/api/org-units/'.$this->id['dept'].'/errors', $body);
        $I->seeResponseCodeIs(201);

        return (int) $I->grabDataFromResponseByJsonPath('$.id')[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function sprint(FunctionalTester $I, string $week): array
    {
        $I->sendGet('/api/org-units/'.$this->id['dept'].'/sprint', ['week' => $week]);
        $I->seeResponseCodeIs(200);

        /** @var array<string, mixed> $data */
        $data = json_decode($I->grabResponse(), true);

        return $data;
    }

    /**
     * @return list<string> titles in the week after pulling recurring tasks
     */
    private function pull(FunctionalTester $I, string $week): array
    {
        $I->sendPost('/api/org-units/'.$this->id['dept'].'/sprint/pull-recurring?week='.$week);
        $I->seeResponseCodeIs(200);

        return array_column($this->sprint($I, $week)['tasks'], 'title');
    }
}
