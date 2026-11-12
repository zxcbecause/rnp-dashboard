<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Auth\User;
use App\Entity\Org\OrgUnit;
use App\Service\Org\OrgTreeManager;
use App\Tests\Support\FunctionalTester;

/**
 * Administration screen API: accounts (/api/admin/users) and the org tree (/api/admin/org-units).
 */
final class AdminCest
{
    private OrgUnit $company;

    public function _before(FunctionalTester $I): void
    {
        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $this->company = $tree->createUnit('Company');
        $I->haveUser('boss@adm.local', 'Boss', ['ROLE_ADMIN']);
        $I->haveUser('head@adm.local', 'Head');
    }

    public function onlyAdministratorsGetIn(FunctionalTester $I): void
    {
        $I->amLoggedInAs('head@adm.local');
        foreach (['/api/admin/users', '/api/admin/org-units'] as $url) {
            $I->sendGet($url);
            $I->seeResponseCodeIs(403);
        }
        $I->sendPost('/api/admin/users', ['email' => 'x@adm.local', 'name' => 'X']);
        $I->seeResponseCodeIs(403);
    }

    public function accountLifecycle(FunctionalTester $I): void
    {
        $I->amLoggedInAs('boss@adm.local');

        $I->sendPost('/api/admin/users', ['email' => 'not-an-email', 'name' => 'Ana']);
        $I->seeResponseCodeIs(422);
        $I->sendPost('/api/admin/users', ['email' => 'Ana@Adm.local', 'name' => ' Ana ']);
        $I->seeResponseCodeIs(201);
        /** @var array{password: string, item: array{id: int, email: string, name: string, isAdmin: bool}} $created */
        $created = json_decode($I->grabResponse(), true, 512, \JSON_THROW_ON_ERROR);
        $I->assertSame(['ana@adm.local', 'Ana', false], [$created['item']['email'], $created['item']['name'], $created['item']['isAdmin']]);
        $I->assertMatchesRegularExpression('/^[A-Za-z2-9]{14}$/', $created['password']);
        $id = $created['item']['id'];

        $I->sendPost('/api/admin/users', ['email' => 'ana@adm.local', 'name' => 'Again']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['error' => 'Пользователь ana@adm.local уже есть.']);

        $I->sendGet('/api/admin/users');
        $I->dontSeeResponseContains($created['password']);
        $I->dontSeeResponseContains('"password"');

        // The generated password works; a reset replaces it and ends the sessions.
        $session = $this->login($I, 'ana@adm.local', $created['password']);
        $I->amLoggedInAs('boss@adm.local');
        $I->sendPost('/api/admin/users/'.$id.'/reset-password');
        $I->seeResponseCodeIs(200);
        /** @var array{password: string} $reset */
        $reset = json_decode($I->grabResponse(), true, 512, \JSON_THROW_ON_ERROR);
        $this->refreshIsRefused($I, $session);
        $this->loginFails($I, 'ana@adm.local', $created['password']);
        $session = $this->login($I, 'ana@adm.local', $reset['password']);

        // Rename + grant admin; then switching off ends the sessions and the login.
        $I->amLoggedInAs('boss@adm.local');
        $I->sendPut('/api/admin/users/'.$id, ['name' => 'Ana María', 'isAdmin' => true, 'isActive' => true]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['name' => 'Ana María', 'isAdmin' => true, 'isActive' => true]);
        $I->sendPut('/api/admin/users/'.$id, ['name' => 'Ana María', 'isAdmin' => false, 'isActive' => false]);
        $I->seeResponseCodeIs(200);
        $this->refreshIsRefused($I, $session);
        $this->loginFails($I, 'ana@adm.local', $reset['password']);

        // An account without history can be deleted; the own one cannot.
        $I->amLoggedInAs('boss@adm.local');
        $I->sendDelete('/api/admin/users/'.$id);
        $I->seeResponseCodeIs(204);
        $I->sendDelete('/api/admin/users/'.$id);
        $I->seeResponseCodeIs(404);
    }

    public function anAdministratorCannotLockItselfOut(FunctionalTester $I): void
    {
        $boss = $I->grabEntityManager()->getRepository(User::class)->findOneBy(['email' => 'boss@adm.local']);
        $id = (int) $boss?->getId();
        $I->amLoggedInAs('boss@adm.local');

        $I->sendPut('/api/admin/users/'.$id, ['name' => 'Boss', 'isAdmin' => false, 'isActive' => true]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['error' => 'Нельзя снять права администратора с себя.']);
        $I->sendPut('/api/admin/users/'.$id, ['name' => 'Renamed', 'isAdmin' => true, 'isActive' => false]);
        $I->seeResponseCodeIs(422);
        $I->sendDelete('/api/admin/users/'.$id);
        $I->seeResponseCodeIs(422);

        // A refused change changes nothing, the rename included.
        $I->sendGet('/api/admin/users');
        $I->seeResponseContainsJson(['email' => 'boss@adm.local', 'name' => 'Boss', 'isAdmin' => true, 'isActive' => true]);

        // The console has no "self", but keeps the last administrator — deleting included.
        $I->runSymfonyConsoleCommand('app:user', ['action' => 'revoke-admin', 'email' => 'boss@adm.local'], [], 2);
        $I->runSymfonyConsoleCommand('app:user', ['action' => 'deactivate', 'email' => 'boss@adm.local'], [], 2);
        $output = $I->runSymfonyConsoleCommand('app:user', ['action' => 'delete', 'email' => 'boss@adm.local'], [], 2);
        $I->assertStringContainsString('последний активный администратор', $output);
    }

    public function orgTreeIsManaged(FunctionalTester $I): void
    {
        $I->haveUser('viewer@adm.local', 'Viewer');
        $I->haveUser('off@adm.local', 'Off', [], false);
        $I->amLoggedInAs('boss@adm.local');

        $sales = $this->createUnit($I, 'Sales', (int) $this->company->getId());
        $team = $this->createUnit($I, 'Team', $sales);
        $I->sendPost('/api/admin/org-units', ['name' => 'sales ', 'parentId' => $this->company->getId()]);
        $I->seeResponseCodeIs(422);

        // Head: sees the branch at once; a deactivated account cannot be appointed.
        $I->sendPut('/api/admin/org-units/'.$sales.'/head', ['userId' => $this->userId($I, 'off@adm.local')]);
        $I->seeResponseCodeIs(422);
        $I->sendPut('/api/admin/org-units/'.$sales.'/head', ['userId' => $this->userId($I, 'head@adm.local')]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['headUserId' => $this->userId($I, 'head@adm.local'), 'members' => [['email' => 'head@adm.local', 'role' => 'head']]]);
        $I->amLoggedInAs('head@adm.local');
        $I->sendGet('/api/org-units/'.$team.'/rnp');
        $I->seeResponseCodeIs(200);

        // Members: a viewer of Team sees Team, not Sales; "head" is not a member role.
        $I->amLoggedInAs('boss@adm.local');
        $I->sendPut('/api/admin/org-units/'.$team.'/members', ['userId' => $this->userId($I, 'viewer@adm.local'), 'role' => 'head']);
        $I->seeResponseCodeIs(422);
        $I->sendPut('/api/admin/org-units/'.$team.'/members', ['userId' => $this->userId($I, 'viewer@adm.local'), 'role' => 'viewer']);
        $I->seeResponseCodeIs(200);
        $I->amLoggedInAs('viewer@adm.local');
        $I->sendGet('/api/org-units/'.$team.'/rnp');
        $I->seeResponseCodeIs(200);
        $I->sendGet('/api/org-units/'.$sales.'/rnp');
        $I->seeResponseCodeIs(404);

        // Rename + move with the branch: Sales under a new Commercial unit; not into its own branch.
        $I->amLoggedInAs('boss@adm.local');
        $commercial = $this->createUnit($I, 'Commercial', (int) $this->company->getId());
        $I->sendPut('/api/admin/org-units/'.$sales, ['name' => 'Sales', 'parentId' => $team]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['error' => 'Нельзя перенести отдел внутрь его же ветки.']);
        // The new name is checked where the unit goes: "Commercial" is taken under Company, not under Commercial.
        $I->sendPut('/api/admin/org-units/'.$sales, ['name' => 'Commercial', 'parentId' => $commercial]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['id' => $sales, 'name' => 'Commercial', 'parentId' => $commercial]);
        $I->sendPut('/api/admin/org-units/'.$sales, ['name' => 'Sales B2B', 'parentId' => $commercial]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['id' => $sales, 'name' => 'Sales B2B', 'parentId' => $commercial]);
        $I->sendPut('/api/admin/org-units/'.$this->company->getId(), ['name' => 'Company', 'parentId' => $commercial]);
        $I->seeResponseCodeIs(422);

        // Removing the head: the unit has none; the former head loses the branch but still sees the unit, read only.
        $I->sendPut('/api/admin/org-units/'.$sales.'/head', ['userId' => null]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['headUserId' => null, 'members' => [['email' => 'head@adm.local', 'role' => 'viewer']]]);
        $I->sendDelete('/api/admin/org-units/'.$team.'/members/'.$this->userId($I, 'viewer@adm.local'));
        $I->seeResponseCodeIs(200);
        $I->amLoggedInAs('head@adm.local');
        $I->sendGet('/api/org-units/'.$team.'/rnp');
        $I->seeResponseCodeIs(404);
        $I->sendGet('/api/org-units/'.$sales.'/rnp');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['rows' => []]);
    }

    private function createUnit(FunctionalTester $I, string $name, int $parentId): int
    {
        $I->sendPost('/api/admin/org-units', ['name' => $name, 'parentId' => $parentId]);
        $I->seeResponseCodeIs(201);
        /** @var array{id: int} $unit */
        $unit = json_decode($I->grabResponse(), true, 512, \JSON_THROW_ON_ERROR);

        return $unit['id'];
    }

    private function userId(FunctionalTester $I, string $email): int
    {
        $user = $I->grabEntityManager()->getRepository(User::class)->findOneBy(['email' => $email]);

        return (int) $user?->getId();
    }

    /**
     * @return string the refresh token
     */
    private function login(FunctionalTester $I, string $email, string $password): string
    {
        $I->deleteHeader('Authorization');
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/login', ['username' => $email, 'password' => $password]);
        $I->seeResponseCodeIs(200);
        /** @var array{refreshToken: string} $session */
        $session = json_decode($I->grabResponse(), true, 512, \JSON_THROW_ON_ERROR);

        return $session['refreshToken'];
    }

    private function loginFails(FunctionalTester $I, string $email, string $password): void
    {
        $I->deleteHeader('Authorization');
        $I->sendPost('/api/login', ['username' => $email, 'password' => $password]);
        $I->seeResponseCodeIs(401);
    }

    private function refreshIsRefused(FunctionalTester $I, string $refreshToken): void
    {
        $I->deleteHeader('Authorization');
        $I->sendPost('/api/token/refresh', ['refreshToken' => $refreshToken]);
        $I->seeResponseCodeIs(401);
    }
}
