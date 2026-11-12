<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\FunctionalTester;
use App\Tests\Support\Helper\Functional;

final class AuthCest
{
    public function loginReturnsAccessAndRefreshTokens(FunctionalTester $I): void
    {
        $I->haveUser('login@test.local');

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/login', ['username' => 'login@test.local', 'password' => Functional::PASSWORD]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseMatchesJsonType(['token' => 'string', 'refreshToken' => 'string', 'refreshTokenExpiration' => 'integer']);
    }

    public function loginIsCaseInsensitiveForEmail(FunctionalTester $I): void
    {
        $I->haveUser('case@test.local');

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/login', ['username' => 'Case@Test.LOCAL', 'password' => Functional::PASSWORD]);
        $I->seeResponseCodeIs(200);
    }

    public function wrongPasswordIsRejected(FunctionalTester $I): void
    {
        $I->haveUser('wrong@test.local');

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/login', ['username' => 'wrong@test.local', 'password' => 'nope']);
        $I->seeResponseCodeIs(401);
    }

    public function inactiveUserCannotLogIn(FunctionalTester $I): void
    {
        $I->haveUser('inactive@test.local', active: false);

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/login', ['username' => 'inactive@test.local', 'password' => Functional::PASSWORD]);
        $I->seeResponseCodeIs(401);
    }

    public function refreshTokenIssuesNewTokenAndIsSingleUse(FunctionalTester $I): void
    {
        $I->haveUser('refresh@test.local');

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/login', ['username' => 'refresh@test.local', 'password' => Functional::PASSWORD]);
        $refreshToken = $I->grabDataFromResponseByJsonPath('$.refreshToken')[0];

        $I->sendPost('/api/token/refresh', ['refreshToken' => $refreshToken]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseMatchesJsonType(['token' => 'string', 'refreshToken' => 'string']);
        $newToken = $I->grabDataFromResponseByJsonPath('$.token')[0];

        $I->sendPost('/api/token/refresh', ['refreshToken' => $refreshToken]);
        $I->seeResponseCodeIs(401);

        $I->amBearerAuthenticated($newToken);
        $I->sendGet('/api/me');
        $I->seeResponseCodeIs(200);
    }

    public function logoutInvalidatesRefreshToken(FunctionalTester $I): void
    {
        $I->haveUser('logout@test.local');

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/login', ['username' => 'logout@test.local', 'password' => Functional::PASSWORD]);
        $refreshToken = $I->grabDataFromResponseByJsonPath('$.refreshToken')[0];

        $I->sendPost('/api/logout', ['refreshToken' => $refreshToken]);
        $I->seeResponseCodeIs(200);

        $I->sendPost('/api/token/refresh', ['refreshToken' => $refreshToken]);
        $I->seeResponseCodeIs(401);
    }

    public function invalidBearerTokenIsRejected(FunctionalTester $I): void
    {
        $I->amBearerAuthenticated('not-a-jwt');
        $I->sendGet('/api/me');
        $I->seeResponseCodeIs(401);
    }
}
