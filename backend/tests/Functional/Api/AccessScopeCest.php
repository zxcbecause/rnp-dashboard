<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Metric\Metric;
use App\Entity\Org\OrgMembership;
use App\Entity\Org\OrgUnit;
use App\Enum\MetricUnit;
use App\Enum\OrgRole;
use App\Service\Org\OrgTreeManager;
use App\Tests\Support\FunctionalTester;

/**
 * Tree used in every test:
 *
 *   Company
 *   ├── Revenue
 *   │   └── Revenue Team
 *   └── Marketing
 */
final class AccessScopeCest
{
    /** @var array<string, int> */
    private array $unit = [];

    /** @var array<string, int> */
    private array $metric = [];

    public function _before(FunctionalTester $I): void
    {
        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $em = $I->grabEntityManager();

        $company = $tree->createUnit('Company');
        $revenue = $tree->createUnit('Revenue', $company, 10);
        $team = $tree->createUnit('Revenue Team', $revenue, 10);
        $marketing = $tree->createUnit('Marketing', $company, 20);

        $units = ['company' => $company, 'revenue' => $revenue, 'team' => $team, 'marketing' => $marketing];
        foreach ($units as $key => $unit) {
            $metric = new Metric($unit, $key.'_metric', ucfirst($key).' metric', MetricUnit::Count);
            $em->persist($metric);
            $em->flush();
            $this->unit[$key] = (int) $unit->getId();
            $this->metric[$key] = (int) $metric->getId();
        }

        $this->member($I, 'head.revenue@test.local', $revenue, OrgRole::Head);
        $this->member($I, 'viewer.revenue@test.local', $revenue, OrgRole::Viewer);
        $this->member($I, 'member.revenue@test.local', $revenue, OrgRole::Member);
        $this->member($I, 'org.admin@test.local', $team, OrgRole::Admin);
        $this->member($I, 'head.company@test.local', $company, OrgRole::Head);
        $I->haveUser('global.admin@test.local', 'Global admin', ['ROLE_ADMIN']);
        $I->haveUser('nobody@test.local', 'No memberships');
    }

    public function headSeesOwnUnitAndAllDescendants(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head.revenue@test.local');

        $I->assertSame(['revenue', 'team'], $this->visibleUnits($I));
        $I->sendGet('/api/org-units/'.$this->unit['team']);
        $I->seeResponseCodeIs(200);
        $I->sendGet('/api/metrics/'.$this->metric['team']);
        $I->seeResponseCodeIs(200);
    }

    public function headGetsNotFoundForSiblingAndParentBranches(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head.revenue@test.local');

        foreach (['marketing', 'company'] as $key) {
            $I->sendGet('/api/org-units/'.$this->unit[$key]);
            $I->seeResponseCodeIs(404);
            $I->sendGet('/api/org-units/'.$this->unit[$key].'/metrics');
            $I->seeResponseCodeIs(404);
            $I->sendGet('/api/metrics/'.$this->metric[$key]);
            $I->seeResponseCodeIs(404);
        }
    }

    public function foreignAndMissingUnitsAreIndistinguishable(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head.revenue@test.local');

        $I->sendGet('/api/org-units/'.$this->unit['marketing']);
        $foreign = $I->grabResponse();
        $I->sendGet('/api/org-units/999999999');
        $I->seeResponseCodeIs(404);
        $I->assertSame($foreign, $I->grabResponse());
    }

    public function viewerSeesOnlyOwnUnit(FunctionalTester $I): void
    {
        $I->amLoggedInAs('viewer.revenue@test.local');

        $I->assertSame(['revenue'], $this->visibleUnits($I));
        $I->sendGet('/api/org-units/'.$this->unit['revenue'].'/metrics');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([['code' => 'revenue_metric']]);
        $I->sendGet('/api/org-units/'.$this->unit['team']);
        $I->seeResponseCodeIs(404);
        $I->sendGet('/api/metrics/'.$this->metric['team']);
        $I->seeResponseCodeIs(404);
    }

    public function memberSeesOnlyOwnUnit(FunctionalTester $I): void
    {
        $I->amLoggedInAs('member.revenue@test.local');

        $I->assertSame(['revenue'], $this->visibleUnits($I));
        $I->sendGet('/api/org-units/'.$this->unit['team']);
        $I->seeResponseCodeIs(404);
    }

    public function orgAdminMembershipSeesEverything(FunctionalTester $I): void
    {
        // Admin membership on a leaf unit still grants the whole tree.
        $I->amLoggedInAs('org.admin@test.local');

        $I->assertSame(['company', 'revenue', 'team', 'marketing'], $this->visibleUnits($I));
        $I->sendGet('/api/metrics/'.$this->metric['marketing']);
        $I->seeResponseCodeIs(200);
    }

    public function globalAdminRoleSeesEverything(FunctionalTester $I): void
    {
        $I->amLoggedInAs('global.admin@test.local');

        $I->assertSame(['company', 'revenue', 'team', 'marketing'], $this->visibleUnits($I));
    }

    public function topManagementSeesTheRoot(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head.company@test.local');

        $I->assertSame(['company', 'revenue', 'team', 'marketing'], $this->visibleUnits($I));
        $I->sendGet('/api/me');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['rootUnitIds' => [$this->unit['company']], 'isAdmin' => false]);
    }

    public function userWithoutMembershipSeesNothing(FunctionalTester $I): void
    {
        $I->amLoggedInAs('nobody@test.local');

        $I->assertSame([], $this->visibleUnits($I));
        $I->sendGet('/api/org-units/'.$this->unit['company']);
        $I->seeResponseCodeIs(404);
        $I->sendGet('/api/me');
        $I->seeResponseContainsJson(['memberships' => [], 'rootUnitIds' => []]);
    }

    public function meReportsCabinetEntryPoint(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head.revenue@test.local');

        $I->sendGet('/api/me');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'email' => 'head.revenue@test.local',
            'rootUnitIds' => [$this->unit['revenue']],
            'memberships' => [['orgUnitId' => $this->unit['revenue'], 'role' => 'head']],
        ]);
    }

    public function anonymousIsRejected(FunctionalTester $I): void
    {
        $I->sendGet('/api/org-units');
        $I->seeResponseCodeIs(401);
        $I->sendGet('/api/metrics/'.$this->metric['company']);
        $I->seeResponseCodeIs(401);
    }

    private function member(FunctionalTester $I, string $email, OrgUnit $unit, OrgRole $role): void
    {
        $user = $I->haveUser($email);
        $em = $I->grabEntityManager();
        $em->persist(new OrgMembership($user, $unit, $role));
        $em->flush();
    }

    /**
     * @return list<string> keys of the visible units in API order
     */
    private function visibleUnits(FunctionalTester $I): array
    {
        $I->sendGet('/api/org-units');
        $I->seeResponseCodeIs(200);

        /** @var list<array{id: int}> $units */
        $units = json_decode($I->grabResponse(), true, 512, \JSON_THROW_ON_ERROR);
        $byId = array_flip($this->unit);

        return array_map(static fn (array $u): string => $byId[$u['id']], $units);
    }
}
