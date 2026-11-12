<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Auth\RefreshToken;
use App\Entity\Auth\User;
use App\Entity\Metric\Metric;
use App\Entity\Metric\MetricValue;
use App\Entity\Metric\UserMetricPref;
use App\Entity\Org\OrgMembership;
use App\Entity\Org\OrgUnit;
use App\Enum\MetricUnit;
use App\Enum\OrgRole;
use App\Enum\PeriodType;
use App\Enum\ValueOrigin;
use App\Service\Org\OrgTreeManager;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\Helper\Functional;
use BcMath\Number;

/**
 * Accounts from the console (app:user, app:org) and the self-service password change.
 */
final class AccountCest
{
    private const string PASSWORD = Functional::PASSWORD;

    public function userChangesOwnPassword(FunctionalTester $I): void
    {
        $I->haveUser('marta@acc.local');
        $I->amLoggedInAs('marta@acc.local');

        $I->sendPut('/api/me/password', ['currentPassword' => 'wrong-one', 'newPassword' => 'brand-new-secret']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['error' => 'Текущий пароль указан неверно.']);

        $I->sendPut('/api/me/password', ['currentPassword' => self::PASSWORD, 'newPassword' => 'short']);
        $I->seeResponseCodeIs(422);

        $I->sendPut('/api/me/password', ['currentPassword' => self::PASSWORD, 'newPassword' => 'brand-new-secret']);
        $I->seeResponseCodeIs(204);

        $I->deleteHeader('Authorization');
        $I->sendPost('/api/login', ['username' => 'marta@acc.local', 'password' => self::PASSWORD]);
        $I->seeResponseCodeIs(401);
        $I->sendPost('/api/login', ['username' => 'marta@acc.local', 'password' => 'brand-new-secret']);
        $I->seeResponseCodeIs(200);
    }

    /**
     * The 5-per-15-minutes limit itself is not asserted here: the test client reboots the kernel between
     * requests and the limiter state does not survive it (checked on the dev server: 5 × 422, then 429).
     */
    public function passwordChangeRevokesSessions(FunctionalTester $I): void
    {
        $I->haveUser('gleb@acc.local');
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/login', ['username' => 'gleb@acc.local', 'password' => self::PASSWORD]);
        /** @var array{token: string, refreshToken: string} $session */
        $session = json_decode($I->grabResponse(), true, 512, \JSON_THROW_ON_ERROR);
        $I->amBearerAuthenticated($session['token']);

        $I->sendPut('/api/me/password', ['currentPassword' => self::PASSWORD, 'newPassword' => 'another-secret-1']);
        $I->seeResponseCodeIs(204);

        $I->deleteHeader('Authorization');
        $I->sendPost('/api/token/refresh', ['refreshToken' => $session['refreshToken']]);
        $I->seeResponseCodeIs(401);
    }

    public function adminRightsAreGrantedAndRevoked(FunctionalTester $I): void
    {
        $I->haveUser('regina@acc.local');
        // Someone else keeps the keys: the last administrator cannot be demoted.
        $I->haveUser('gestion@acc.local', 'Gestion', ['ROLE_ADMIN']);

        $I->runSymfonyConsoleCommand('app:user', ['action' => 'grant-admin', 'email' => 'regina@acc.local']);
        $I->amLoggedInAs('regina@acc.local');
        $I->sendGet('/api/admin/ingest-tokens');
        $I->seeResponseCodeIs(200);

        $I->runSymfonyConsoleCommand('app:user', ['action' => 'revoke-admin', 'email' => 'regina@acc.local']);
        $I->amLoggedInAs('regina@acc.local');
        $I->sendGet('/api/admin/ingest-tokens');
        $I->seeResponseCodeIs(403);
    }

    public function passwordChangeNeedsLogin(FunctionalTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPut('/api/me/password', ['currentPassword' => 'x', 'newPassword' => 'yyyyyyyyyyyy']);
        $I->seeResponseCodeIs(401);
    }

    public function createdUserGetsAPasswordOnlyInTheFile(FunctionalTester $I): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'pw');
        try {
            $output = $I->runSymfonyConsoleCommand('app:user', ['action' => 'create', 'email' => 'Lidia@Acme.test', 'name' => 'Lidia Sokolova', '--password-file' => $file]);
            $line = trim((string) file_get_contents($file));
            [$email, $name, $password] = explode("\t", $line);

            $I->assertSame(['lidia@acme.test', 'Lidia Sokolova'], [$email, $name]);
            $I->assertMatchesRegularExpression('/^[A-Za-z2-9]{14}$/', $password);
            $I->assertStringNotContainsString($password, $output, 'the password is not printed');

            $I->haveHttpHeader('Content-Type', 'application/json');
            $I->sendPost('/api/login', ['username' => 'lidia@acme.test', 'password' => $password]);
            $I->seeResponseCodeIs(200);
        } finally {
            @unlink($file);
        }

        $I->runSymfonyConsoleCommand('app:user', ['action' => 'create', 'email' => 'lidia@acme.test', 'name' => 'Again'], [], 2);
    }

    public function orgCommandCreatesUnitsAndReplacesTheHead(FunctionalTester $I): void
    {
        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $company = $tree->createUnit('Company');
        $old = $I->haveUser('old@acc.local');
        $new = $I->haveUser('new@acc.local');

        $output = $this->org($I, 'create-unit', ['Sales', (string) $company->getId()]);
        $I->assertStringContainsString('Unit "Sales" created', $output);
        $this->org($I, 'create-unit', ['sales', (string) $company->getId()], 2);

        $em = $I->grabEntityManager();
        $sales = $em->getRepository(OrgUnit::class)->findOneBy(['name' => 'Sales']);
        $I->assertNotNull($sales);
        $id = (string) $sales->getId();

        $this->org($I, 'set-head', [$id, 'old@acc.local']);
        $this->org($I, 'set-head', [$id, 'new@acc.local']);

        $em->clear();
        $sales = $em->find(OrgUnit::class, (int) $id);
        $I->assertSame('new@acc.local', $sales?->getHeadUser()?->getEmail());
        $roles = [];
        foreach ($em->getRepository(OrgMembership::class)->findBy(['orgUnit' => $sales]) as $m) {
            $roles[$m->getUser()->getEmail()] = $m->getRole();
        }
        ksort($roles);
        $I->assertSame(['new@acc.local' => OrgRole::Head, 'old@acc.local' => OrgRole::Viewer], $roles, 'the previous head loses the branch, stays as a viewer');

        // Branch access follows: the new head sees Sales.
        $I->amLoggedInAs('new@acc.local');
        $I->sendGet('/api/org-units/'.$id.'/rnp');
        $I->seeResponseCodeIs(200);
        unset($old, $new);
        $I->assertInstanceOf(User::class, $em->getRepository(User::class)->findOneBy(['email' => 'old@acc.local']));
    }

    public function deletingAnAccountHandsItsMetricsToTheUnitHead(FunctionalTester $I): void
    {
        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $em = $I->grabEntityManager();
        $unit = $tree->createUnit('Revenue');
        $headless = $tree->createUnit('HR');
        $head = $I->haveUser('marta@acc.local');
        $test = $I->haveUser('marta@test.local');
        $writer = $I->haveUser('writer@test.local');
        $unit->setHeadUser($head);
        $headless->setHeadUser($test);

        $metric = (new Metric($unit, 'del_sum', 'Sum', MetricUnit::Eur))->setOwnerUser($test)->setFillerUser($test);
        $orphan = (new Metric($headless, 'del_hr', 'HR', MetricUnit::Count))->setOwnerUser($test);
        $em->persist($metric);
        $em->persist($orphan);
        $em->persist(new OrgMembership($test, $unit, OrgRole::Member));
        $em->persist(new UserMetricPref($test, $metric));
        $em->persist(new MetricValue($metric, PeriodType::Week, new \DateTimeImmutable('2026-11-02'), new Number('5'), ValueOrigin::Manual, $writer));
        $em->flush();
        $I->amLoggedInAs('marta@test.local');

        $output = $I->runSymfonyConsoleCommand('app:user', ['action' => 'delete', 'email' => 'marta@test.local']);
        $I->assertStringContainsString('2 metric(s)', $output);

        $em->clear();
        $I->assertNull($em->getRepository(User::class)->findOneBy(['email' => 'marta@test.local']));
        $metric = $em->getRepository(Metric::class)->findOneBy(['code' => 'del_sum']);
        $I->assertSame(['marta@acc.local', 'marta@acc.local'], [$metric?->getOwnerUser()?->getEmail(), $metric?->getFillerUser()?->getEmail()]);
        $orphan = $em->getRepository(Metric::class)->findOneBy(['code' => 'del_hr']);
        $I->assertNull($orphan?->getOwnerUser(), 'its own unit: no successor');
        $I->assertNull($orphan?->getOrgUnit()->getHeadUser());
        $I->seeNumRecords(0, RefreshToken::class, ['username' => 'marta@test.local']);

        // Someone who entered a value keeps the account: it is deactivated instead.
        $output = $I->runSymfonyConsoleCommand('app:user', ['action' => 'delete', 'email' => 'writer@test.local'], [], 2);
        $I->assertStringContainsString('metric_value.entered_by (1)', $output);
        $I->assertNotNull($em->getRepository(User::class)->findOneBy(['email' => 'writer@test.local']));
    }

    public function movingAUnitMovesItsBranchAndAccess(FunctionalTester $I): void
    {
        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $company = $tree->createUnit('Company');
        $revenue = $tree->createUnit('Revenue', $company);
        $ota = $tree->createUnit('OTA', $revenue);
        $content = $tree->createUnit('Content', $ota);
        $sales = $tree->createUnit('Sales', $company);
        $I->haveUser('revenue@move.local');
        $I->haveUser('sales@move.local');
        $this->org($I, 'set-head', [(string) $revenue->getId(), 'revenue@move.local']);
        $this->org($I, 'set-head', [(string) $sales->getId(), 'sales@move.local']);

        $output = $this->org($I, 'move-unit', [(string) $ota->getId(), (string) $sales->getId()]);
        $I->assertStringContainsString('"OTA" moved from "Revenue" to "Sales"', $output);

        $em = $I->grabEntityManager();
        $em->clear();
        $I->assertSame($sales->getId(), $em->find(OrgUnit::class, (int) $ota->getId())?->getParent()?->getId());
        $I->assertSame(
            [(int) $content->getId() => 0, (int) $ota->getId() => 1, (int) $sales->getId() => 2, (int) $company->getId() => 3],
            $this->ancestors($I, (int) $content->getId()),
            'paths inside the branch stay, the old ancestor (Revenue) is gone, the new ones are added',
        );
        $I->assertSame([(int) $revenue->getId() => 0, (int) $company->getId() => 1], $this->ancestors($I, (int) $revenue->getId()));

        $I->amLoggedInAs('revenue@move.local');
        $I->sendGet('/api/org-units/'.$content->getId().'/rnp');
        $I->seeResponseCodeIs(404);
        $I->amLoggedInAs('sales@move.local');
        $I->sendGet('/api/org-units/'.$content->getId().'/rnp');
        $I->seeResponseCodeIs(200);

        // Not into its own branch, not the root.
        $this->org($I, 'move-unit', [(string) $ota->getId(), (string) $content->getId()], 2);
        $this->org($I, 'move-unit', [(string) $ota->getId(), (string) $ota->getId()], 2);
        $this->org($I, 'move-unit', [(string) $company->getId(), (string) $sales->getId()], 2);
        $I->assertCount(4, $this->ancestors($I, (int) $content->getId()), 'a refused move changes nothing');
    }

    /**
     * @return array<int, int> ancestor id => depth, nearest first
     */
    private function ancestors(FunctionalTester $I, int $unitId): array
    {
        $rows = $I->grabEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT ancestor_id, depth FROM org_unit_path WHERE descendant_id = ? ORDER BY depth',
            [$unitId],
        );

        return array_map(intval(...), array_combine(array_map(intval(...), array_keys($rows)), $rows));
    }

    /**
     * @param list<string> $args
     */
    private function org(FunctionalTester $I, string $action, array $args, int $exitCode = 0): string
    {
        // app:org takes an array argument; Symfony accepts it, the generated helper's PHPDoc does not say so.
        /** @var array<int|string, bool|int|string> $parameters */
        $parameters = ['action' => $action, 'args' => $args];

        return $I->runSymfonyConsoleCommand('app:org', $parameters, [], $exitCode);
    }
}
