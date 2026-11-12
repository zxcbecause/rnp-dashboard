<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\FunctionalTester;

final class HealthCest
{
    public function healthIsPublicAndReportsDatabase(FunctionalTester $I): void
    {
        $I->sendGet('/api/health');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['status' => 'ok', 'database' => 'ok']);
    }

    public function openApiDocumentIsPublic(FunctionalTester $I): void
    {
        $I->sendGet('/api/doc.json');
        $I->seeResponseCodeIs(200);
        $I->seeResponseJsonMatchesJsonPath("$.paths['/api/health']");
    }

    public function unknownApiRouteAnswersJson404(FunctionalTester $I): void
    {
        $I->sendGet('/api/does-not-exist');
        $I->seeResponseCodeIs(404);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson(['error' => 'Not found.']);
    }
}
