<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Auth\User;
use App\Entity\Journal\CallExclusion;
use App\Entity\Metric\Metric;
use App\Entity\Org\OrgMembership;
use App\Entity\Org\OrgUnit;
use App\Enum\CallExclusionStatus;
use App\Enum\MetricSourceType;
use App\Enum\MetricUnit;
use App\Enum\OrgRole;
use App\Service\Org\OrgTreeManager;
use App\Tests\Support\FunctionalTester;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;

/**
 * Missed calls and exclusion requests: CS owns a "missed calls" Bitrix metric, Sales does not.
 * Calls (Madrid time): 101 and 102 missed yesterday, 103 answered yesterday, 104 missed three months ago.
 */
final class CallExclusionCest
{
    /** @var array<string, int> */
    private array $id = [];

    public function _before(FunctionalTester $I): void
    {
        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $company = $tree->createUnit('Company');
        $cs = $tree->createUnit('CS', $company);
        $sales = $tree->createUnit('Sales', $company);
        $em = $I->grabEntityManager();

        $missed = (new Metric($cs, 't_missed', 'Пропущенные', MetricUnit::Count))
            ->setSourceType(MetricSourceType::Bitrix)
            ->setSourceConfig(['calculator' => 'calls', 'direction' => 'incoming', 'missed' => true]);
        $em->persist($missed);
        $total = (new Metric($cs, 't_total', 'Входящие', MetricUnit::Count))
            ->setSourceType(MetricSourceType::Bitrix)
            ->setSourceConfig(['calculator' => 'calls', 'direction' => 'incoming']);
        $em->persist($total);
        foreach (['head' => OrgRole::Head, 'manager' => OrgRole::Member, 'manager2' => OrgRole::Member, 'viewer' => OrgRole::Viewer] as $name => $role) {
            $em->persist(new OrgMembership($I->haveUser($name.'@rnp.local', ucfirst($name)), $cs, $role));
        }
        $em->persist(new OrgMembership($I->haveUser('sales@rnp.local'), $sales, OrgRole::Head));
        $em->flush();

        $this->id = ['cs' => (int) $cs->getId(), 'sales' => (int) $sales->getId(), 'missed' => (int) $missed->getId(), 'total' => (int) $total->getId()];

        /** @var Connection $db */
        $db = $I->grabService(Connection::class);
        $yesterday = new \DateTimeImmutable('yesterday');
        foreach ([
            [101, true, $yesterday->setTime(10, 15), 3],
            [102, true, $yesterday->setTime(16, 40), 2],
            [103, false, $yesterday->setTime(11, 0), 3],
            [104, true, $yesterday->modify('-3 months')->setTime(9, 0), 3],
        ] as [$id, $isMissed, $start, $ownerType]) {
            $db->insert('raw_bitrix_call', [
                'id' => $id, 'direction' => 1, 'missed' => (int) $isMissed, 'completed' => 1,
                'start_time' => $start->format('Y-m-d H:i:s'), 'end_time' => $start->format('Y-m-d H:i:s'),
                'owner_type_id' => $ownerType, 'owner_id' => 5000 + $id, 'responsible_id' => 42,
                'payload' => '{}', 'synced_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }
    }

    public function anOldCallIsNotApprovedWhileASyncHoldsTheRecalculation(FunctionalTester $I): void
    {
        // A request for call 104 (three months ago) made back when it was still in the window.
        $em = $I->grabEntityManager();
        $cs = $em->find(OrgUnit::class, $this->id['cs']);
        $manager = $em->getRepository(User::class)->findOneBy(['email' => 'manager@rnp.local']);
        $I->assertNotNull($cs);
        $I->assertNotNull($manager);
        $exclusion = new CallExclusion($cs, 104, (new \DateTimeImmutable('yesterday'))->modify('-3 months')->setTime(9, 0), 42, 'Пропал интернет в офисе', $manager);
        $em->persist($exclusion);
        $em->flush();

        /** @var LockFactory $locks */
        $locks = $I->grabService(LockFactory::class);
        $sync = $locks->createLock('metrics-recalc', 900);
        $I->assertTrue($sync->acquire());
        try {
            $I->amLoggedInAs('head@rnp.local');
            $I->sendPost('/api/call-exclusions/'.$exclusion->getId().'/decision', ['approve' => true]);
            $I->seeResponseCodeIs(409);
        } finally {
            $sync->release();
        }

        // Nothing was saved: the request still waits, and is decided once the sync is over.
        $em->clear();
        $I->assertSame(CallExclusionStatus::Pending, $em->find(CallExclusion::class, $exclusion->getId())?->getStatus());
        $I->sendPost('/api/call-exclusions/'.$exclusion->getId().'/decision', ['approve' => true]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['recalculated' => true]);
    }

    public function managerRequestsAndTheHeadApprovesTheMetricDrops(FunctionalTester $I): void
    {
        $I->amLoggedInAs('manager@rnp.local');
        $list = $this->list($I);
        $I->assertSame([102, 101], array_column($list['calls'], 'callId'), 'missed incoming calls only, newest first');
        $I->assertTrue($list['canRequest']);
        $I->assertFalse($list['canDecide']);
        $I->assertTrue($list['calls'][0]['canRequest']);

        foreach ([
            [['callId' => 101, 'reason' => 'нет'], 'подробнее'],
            [['callId' => 999, 'reason' => 'Пропал интернет в офисе'], 'Такого входящего звонка нет'],
            [['callId' => 101, 'reason' => 'Пропал интернет в офисе', 'category' => 'weather'], 'категория'],
            [['callId' => 104, 'reason' => 'Пропал интернет в офисе'], 'текущего и прошлого месяца'],
        ] as [$body, $message]) {
            $I->sendPost('/api/org-units/'.$this->id['cs'].'/call-exclusions', $body);
            $I->seeResponseCodeIs(422);
            $I->seeResponseContains($message);
        }

        $I->sendPost('/api/org-units/'.$this->id['cs'].'/call-exclusions', ['callId' => 101, 'reason' => 'Пропал интернет в офисе']);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson(['status' => 'pending', 'recalculated' => null]);
        $exclusionId = (int) json_decode($I->grabResponse(), true)['id'];
        $I->sendPost('/api/org-units/'.$this->id['cs'].'/call-exclusions', ['callId' => 101, 'reason' => 'Ещё раз тот же звонок']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContains('уже есть заявка');

        // A member cannot decide, the head can — once.
        $I->sendPost('/api/call-exclusions/'.$exclusionId.'/decision', ['approve' => true]);
        $I->seeResponseCodeIs(403);

        $I->amLoggedInAs('head@rnp.local');
        $list = $this->list($I);
        $I->assertTrue($list['canDecide']);
        $I->assertSame([101], array_column($list['pending'], 'callId'));
        $I->sendGet('/api/notifications');
        $I->seeResponseContainsJson([['type' => 'call_exclusions_pending', 'orgUnitId' => $this->id['cs'], 'count' => 1]]);

        $I->sendPost('/api/call-exclusions/'.$exclusionId.'/decision', ['approve' => true, 'note' => 'Подтверждаю']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['recalculated' => true]);
        $I->sendPost('/api/call-exclusions/'.$exclusionId.'/decision', ['approve' => false]);
        $I->seeResponseCodeIs(422);

        // Two missed calls in the window, one excluded; it leaves the incoming calls too (three before).
        $I->assertSame(1, $this->missedTotal($I));
        $I->assertSame(2, $this->metricTotal($I, 'total'));
        $I->assertSame('technical', $this->list($I)['calls'][1]['exclusion']['category']);
        $list = $this->list($I);
        $I->assertSame([], $list['pending']);
        $I->assertSame('approved', $list['calls'][1]['exclusion']['status']);
        $I->assertSame('Head', $list['calls'][1]['exclusion']['decidedBy']['name']);
        $I->assertFalse($list['calls'][1]['canRequest']);

        $I->amLoggedInAs('manager@rnp.local');
        $I->sendGet('/api/notifications');
        $I->seeResponseContainsJson([['type' => 'call_exclusion_decided', 'status' => 'approved', 'decidedBy' => 'Head']]);
    }

    public function rejectionKeepsTheCallAndWithdrawIsForTheAuthorWhilePending(FunctionalTester $I): void
    {
        $I->amLoggedInAs('manager@rnp.local');
        $I->sendPost('/api/org-units/'.$this->id['cs'].'/call-exclusions', ['callId' => 102, 'reason' => 'Сбой телефонии у оператора']);
        $first = (int) json_decode($I->grabResponse(), true)['id'];

        $I->amLoggedInAs('manager2@rnp.local');
        $I->sendDelete('/api/call-exclusions/'.$first);
        $I->seeResponseCodeIs(403);

        $I->amLoggedInAs('manager@rnp.local');
        $I->sendDelete('/api/call-exclusions/'.$first);
        $I->seeResponseCodeIs(204);

        $I->sendPost('/api/org-units/'.$this->id['cs'].'/call-exclusions', ['callId' => 102, 'reason' => 'Сбой телефонии у оператора']);
        $second = (int) json_decode($I->grabResponse(), true)['id'];
        $I->amLoggedInAs('head@rnp.local');
        $I->sendPost('/api/call-exclusions/'.$second.'/decision', ['approve' => false, 'note' => 'Линия работала']);
        $I->seeResponseCodeIs(200);
        $I->sendDelete('/api/call-exclusions/'.$second);
        $I->seeResponseCodeIs(403, 'a decision is final');
        $I->assertSame('rejected', $this->list($I)['calls'][0]['exclusion']['status']);
    }

    public function viewersReadOnlyAndOtherUnitsAreNotFound(FunctionalTester $I): void
    {
        $I->amLoggedInAs('viewer@rnp.local');
        $I->sendGet('/api/me');
        $I->assertSame([$this->id['cs']], array_column(json_decode($I->grabResponse(), true)['callUnits'], 'id'));
        $list = $this->list($I);
        $I->assertFalse($list['canRequest']);
        $I->assertFalse($list['calls'][0]['canRequest']);
        $I->sendPost('/api/org-units/'.$this->id['cs'].'/call-exclusions', ['callId' => 101, 'reason' => 'Пропал интернет в офисе']);
        $I->seeResponseCodeIs(403);

        // A unit without a missed-calls metric, and a unit the user cannot see.
        $I->sendGet('/api/org-units/'.$this->id['sales'].'/missed-calls');
        $I->seeResponseCodeIs(404);
        $I->amLoggedInAs('sales@rnp.local');
        $I->sendGet('/api/me');
        $I->assertSame([], json_decode($I->grabResponse(), true)['callUnits']);
        $I->sendGet('/api/org-units/'.$this->id['cs'].'/missed-calls');
        $I->seeResponseCodeIs(404);

        $I->sendGet('/api/org-units/'.$this->id['cs'].'/missed-calls', ['from' => '2026-01-01', 'to' => '2026-06-01']);
        $I->seeResponseCodeIs(404);
        $I->amLoggedInAs('head@rnp.local');
        $I->sendGet('/api/org-units/'.$this->id['cs'].'/missed-calls', ['from' => '2026-01-01', 'to' => '2026-06-01']);
        $I->seeResponseCodeIs(400);
    }

    /**
     * @return array<string, mixed>
     */
    private function list(FunctionalTester $I): array
    {
        $I->sendGet('/api/org-units/'.$this->id['cs'].'/missed-calls');
        $I->seeResponseCodeIs(200);

        return json_decode($I->grabResponse(), true);
    }

    private function missedTotal(FunctionalTester $I): int
    {
        /** @var Connection $db */
        $db = $I->grabService(Connection::class);

        return (int) $db->fetchOne('SELECT COALESCE(SUM(value), 0) FROM metric_value WHERE metric_id = ?', [$this->id['missed']]);
    }

    public function theHeadExcludesAnAnsweredSpamCallAtOnce(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@rnp.local');
        // Only missed calls by default; every incoming call on request.
        $I->assertSame([102, 101], array_column($this->list($I)['calls'], 'callId'));
        $I->sendGet('/api/org-units/'.$this->id['cs'].'/missed-calls', ['all' => 'true']);
        $I->seeResponseCodeIs(200);
        $calls = json_decode($I->grabResponse(), true)['calls'];
        $I->assertSame([102 => true, 103 => false, 101 => true], array_column($calls, 'missed', 'callId'));
        $I->assertTrue($calls[1]['canRequest'], 'an answered call can be excluded too');

        // The head decides anyway: no second step.
        $I->sendPost('/api/org-units/'.$this->id['cs'].'/call-exclusions', ['callId' => 103, 'reason' => 'Рекламный обзвон, не клиент', 'category' => 'spam']);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson(['status' => 'approved', 'recalculated' => true]);

        // Three incoming calls, the spam one is out; both missed calls stay.
        $I->assertSame(2, $this->metricTotal($I, 'total'));
        $I->assertSame(2, $this->missedTotal($I));

        $I->sendGet('/api/org-units/'.$this->id['cs'].'/missed-calls', ['all' => 'true']);
        $list = json_decode($I->grabResponse(), true);
        $I->assertSame([], $list['pending']);
        $I->assertSame(['approved', 'spam', 'Head'], [$list['calls'][1]['exclusion']['status'], $list['calls'][1]['exclusion']['category'], $list['calls'][1]['exclusion']['decidedBy']['name']]);
        // Nobody is told about a decision on their own request.
        $I->sendGet('/api/notifications');
        $I->dontSeeResponseContainsJson([['type' => 'call_exclusion_decided']]);
    }

    public function aScreenshotTravelsWithTheRequest(FunctionalTester $I): void
    {
        // A 1×1 PNG.
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $url = '/api/org-units/'.$this->id['cs'].'/call-exclusions';

        $I->amLoggedInAs('manager@rnp.local');
        foreach ([
            ['not base64 at all!', 'не удалось прочитать'],
            [base64_encode('just a text file, not a picture'), 'PNG, JPG или WebP'],
        ] as [$image, $message]) {
            $I->sendPost($url, ['callId' => 101, 'reason' => 'Пропал интернет в офисе', 'image' => $image]);
            $I->seeResponseCodeIs(422);
            $I->seeResponseContains($message);
        }
        $I->assertSame([], $this->list($I)['pending'], 'a refused image leaves no request behind');

        $I->sendPost($url, ['callId' => 101, 'reason' => 'Пропал интернет в офисе', 'image' => $png]);
        $I->seeResponseCodeIs(201);
        $withImage = (int) json_decode($I->grabResponse(), true)['id'];
        $I->sendPost($url, ['callId' => 102, 'reason' => 'Сбой телефонии у оператора']);
        $withoutImage = (int) json_decode($I->grabResponse(), true)['id'];

        // The head sees which requests carry a screenshot and opens it.
        $I->amLoggedInAs('head@rnp.local');
        $I->assertSame([101 => true, 102 => false], array_column($this->list($I)['pending'], 'hasImage', 'callId'));
        $I->assertSame([102 => false, 101 => true], array_map(static fn (array $c): bool => $c['exclusion']['hasImage'], array_column($this->list($I)['calls'], null, 'callId')));
        $I->sendGet('/api/call-exclusions/'.$withImage.'/image');
        $I->seeResponseCodeIs(200);
        $I->assertSame(['contentType' => 'image/png', 'data' => $png], json_decode($I->grabResponse(), true));
        $I->sendGet('/api/call-exclusions/'.$withoutImage.'/image');
        $I->seeResponseCodeIs(404);

        // Other units do not see it; a withdrawn request takes its screenshot along.
        $I->amLoggedInAs('sales@rnp.local');
        $I->sendGet('/api/call-exclusions/'.$withImage.'/image');
        $I->seeResponseCodeIs(404);
        $I->amLoggedInAs('manager@rnp.local');
        $I->sendDelete('/api/call-exclusions/'.$withImage);
        $I->seeResponseCodeIs(204);
        /** @var Connection $db */
        $db = $I->grabService(Connection::class);
        $I->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM call_exclusion_attachment'));
    }

    private function metricTotal(FunctionalTester $I, string $metric): int
    {
        /** @var Connection $db */
        $db = $I->grabService(Connection::class);

        return (int) $db->fetchOne('SELECT COALESCE(SUM(value), 0) FROM metric_value WHERE metric_id = ?', [$this->id[$metric]]);
    }
}
