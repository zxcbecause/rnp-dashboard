<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Sync\SyncRun;
use App\Enum\SyncSource;
use App\Tests\Support\FunctionalTester;
use Doctrine\DBAL\Connection;

/**
 * Platform: every resource succeeded 10 minutes ago, then "tickets" failed. Bitrix: only "calls" ran, 5 hours ago.
 */
final class DataStatusCest
{
    public function _before(FunctionalTester $I): void
    {
        $em = $I->grabEntityManager();
        foreach (['accommodations', 'bookings', 'housekeeping_tasks', 'tickets'] as $resource) {
            $this->run($I, SyncSource::PlatformApi, $resource, '-10 minutes', null);
        }
        $this->run($I, SyncSource::PlatformApi, 'tickets', '-5 minutes', 'GET /api/tickets answered HTTP 500.');
        $this->run($I, SyncSource::Bitrix, 'calls', '-5 hours', null);
        // On-demand sources are not reported.
        $this->run($I, SyncSource::PushApi, 'some-script', '-1 minute', null);
        $em->flush();

        $I->haveUser('user@status.local');
        $I->haveUser('admin@status.local', 'Admin', ['ROLE_ADMIN']);
    }

    public function reportsFreshnessPerSource(FunctionalTester $I): void
    {
        $I->amLoggedInAs('user@status.local');
        $I->sendGet('/api/data-status');
        $I->seeResponseCodeIs(200);

        $sources = $this->sources($I);
        $I->assertSame(['platform_api', 'bitrix'], array_keys($sources));

        $platform = $sources['platform_api'];
        $I->assertTrue($platform['configured']);
        $I->assertFalse($platform['stale'], 'tickets last succeeded 10 minutes ago');
        $I->assertTrue($platform['failing'], 'but its last run failed');
        $I->assertNotNull($platform['dataAsOf']);
        $tickets = array_column($platform['resources'], null, 'resource')['tickets'];
        $I->assertSame('failed', $tickets['lastStatus']);
        $I->assertNull($tickets['error'], 'error texts are for admins');

        $bitrix = $sources['bitrix'];
        $I->assertTrue($bitrix['stale'], 'deals have never been synced, calls are 5 hours old');
        $I->assertNull($bitrix['dataAsOf']);
        $I->assertFalse($bitrix['failing']);
    }

    public function adminsSeeTheErrorText(FunctionalTester $I): void
    {
        $I->amLoggedInAs('admin@status.local');
        $I->sendGet('/api/data-status');

        $tickets = array_column($this->sources($I)['platform_api']['resources'], null, 'resource')['tickets'];
        $I->assertSame('GET /api/tickets answered HTTP 500.', $tickets['error']);
    }

    public function requiresLogin(FunctionalTester $I): void
    {
        $I->sendGet('/api/data-status');
        $I->seeResponseCodeIs(401);
    }

    private function run(FunctionalTester $I, SyncSource $source, string $scope, string $ago, ?string $error): void
    {
        $em = $I->grabEntityManager();
        $run = new SyncRun($source, $scope);
        null === $error ? $run->succeed(1) : $run->fail($error);
        $em->persist($run);
        $em->flush();

        /** @var Connection $db */
        $db = $I->grabService('doctrine.dbal.default_connection');
        $db->executeStatement('UPDATE sync_run SET started_at = ? WHERE id = ?', [new \DateTimeImmutable($ago)->format('Y-m-d H:i:s'), $run->getId()]);
    }

    /**
     * @return array<string, array{configured: bool, dataAsOf: ?string, stale: bool, failing: bool, resources: list<array<string, mixed>>}>
     */
    private function sources(FunctionalTester $I): array
    {
        /** @var array{sources: list<array{source: string, configured: bool, dataAsOf: ?string, stale: bool, failing: bool, resources: list<array<string, mixed>>}>} $data */
        $data = json_decode($I->grabResponse(), true, 512, \JSON_THROW_ON_ERROR);

        return array_column($data['sources'], null, 'source');
    }
}
