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
 * Dept has metrics a, b, c (default order a, b, c); Other has x.
 */
final class MetricViewCest
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

        foreach (['a' => $dept, 'b' => $dept, 'c' => $dept, 'x' => $other] as $code => $unit) {
            $metric = new Metric($unit, 'v_'.$code, 'Metric '.$code, MetricUnit::Count);
            $metric->setSort(\count($this->id));
            $em->persist($metric);
            $em->flush();
            $this->id[$code] = (int) $metric->getId();
        }
        $this->id['dept'] = (int) $dept->getId();
        $this->id['other'] = (int) $other->getId();

        foreach (['head' => OrgRole::Head, 'viewer' => OrgRole::Viewer] as $name => $role) {
            $em->persist(new OrgMembership($I->haveUser($name.'@view.local'), $dept, $role));
        }
        $em->flush();
    }

    public function defaultOrderIsMetricSort(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@view.local');

        $I->assertSame(['a', 'b', 'c'], $this->codes($I));
        $I->seeResponseContainsJson(['hiddenCount' => 0]);
    }

    public function savedOrderAndHiddenMetricsApplyToTheUserOnly(FunctionalTester $I): void
    {
        $I->amLoggedInAs('viewer@view.local');
        $this->save($I, [['c', true], ['a', false], ['b', true]]);
        $I->seeResponseCodeIs(204);

        $I->assertSame(['c', 'b'], $this->codes($I));
        $I->seeResponseContainsJson(['hiddenCount' => 1]);

        // With showHidden the hidden metric comes back in its place, flagged.
        $I->sendGet('/api/org-units/'.$this->id['dept'].'/rnp', ['showHidden' => 'true']);
        $rows = json_decode($I->grabResponse(), true)['rows'];
        $I->assertSame(['v_c' => false, 'v_a' => true, 'v_b' => false], array_column($rows, 'hidden', 'code'));

        // The head's view is untouched.
        $I->amLoggedInAs('head@view.local');
        $I->assertSame(['a', 'b', 'c'], $this->codes($I));
    }

    public function newMetricAppearsAfterArrangedOnes(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@view.local');
        $this->save($I, [['c', true], ['b', true], ['a', true]]);

        $em = $I->grabEntityManager();
        $dept = $em->find(\App\Entity\Org\OrgUnit::class, $this->id['dept']);
        $I->assertNotNull($dept);
        $new = new Metric($dept, 'v_new', 'New', MetricUnit::Count);
        $new->setSort(-1);
        $em->persist($new);
        $em->flush();

        $I->assertSame(['c', 'b', 'a', 'new'], $this->codes($I));
    }

    public function resetRestoresDefault(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@view.local');
        $this->save($I, [['b', true], ['c', false], ['a', true]]);
        $I->assertSame(['b', 'a'], $this->codes($I));

        $I->sendDelete('/api/org-units/'.$this->id['dept'].'/metric-view');
        $I->seeResponseCodeIs(204);
        $I->assertSame(['a', 'b', 'c'], $this->codes($I));
    }

    public function invalidViewsAreRejected(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@view.local');

        $this->save($I, [['a', true], ['b', true]]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['error' => 'Нужно передать все активные метрики отдела, и только их.']);

        $this->save($I, [['a', true], ['b', true], ['c', true], ['x', true]]);
        $I->seeResponseCodeIs(422);

        $this->save($I, [['a', true], ['a', true], ['b', true], ['c', true]]);
        $I->seeResponseCodeIs(422);

        $this->save($I, [['a', false], ['b', false], ['c', false]]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['error' => 'Нельзя скрыть все метрики отдела.']);

        $I->sendPut('/api/org-units/'.$this->id['other'].'/metric-view', ['items' => [['metricId' => $this->id['x'], 'visible' => true]]]);
        $I->seeResponseCodeIs(404);
        $I->sendDelete('/api/org-units/'.$this->id['other'].'/metric-view');
        $I->seeResponseCodeIs(404);
    }

    /**
     * @param list<array{string, bool}> $items
     */
    private function save(FunctionalTester $I, array $items): void
    {
        $I->sendPut('/api/org-units/'.$this->id['dept'].'/metric-view', [
            'items' => array_map(fn (array $i): array => ['metricId' => $this->id[$i[0]], 'visible' => $i[1]], $items),
        ]);
    }

    /**
     * @return list<string> visible metric codes without the prefix, in display order
     */
    private function codes(FunctionalTester $I): array
    {
        $I->sendGet('/api/org-units/'.$this->id['dept'].'/rnp');
        $I->seeResponseCodeIs(200);
        /** @var array{rows: list<array{code: string}>} $data */
        $data = json_decode($I->grabResponse(), true);

        return array_map(static fn (array $r): string => substr($r['code'], 2), $data['rows']);
    }
}
