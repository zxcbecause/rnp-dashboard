<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Metric\Metric;
use App\Entity\Org\OrgUnit;
use App\Entity\Sync\IngestToken;
use App\Enum\MetricSourceType;
use App\Enum\MetricUnit;
use App\Enum\PeriodType;
use App\Enum\TimeAggregation;
use App\Service\Org\OrgTreeManager;
use App\Tests\Support\FunctionalTester;
use Doctrine\DBAL\Connection;

/**
 * Company → Dept → Team, Company → Other. The token belongs to Dept.
 *  p_week (Team, push_api, weekly ratio), p_month (Dept, push_api, monthly count): writable;
 *  p_manual (Dept, manual), p_formula (Dept, push_api formula), p_other (Other, push_api): not writable.
 */
final class IngestCest
{
    private string $token;

    private OrgUnit $dept;

    public function _before(FunctionalTester $I): void
    {
        /** @var OrgTreeManager $tree */
        $tree = $I->grabService(OrgTreeManager::class);
        $company = $tree->createUnit('Company');
        $this->dept = $tree->createUnit('Dept', $company);
        $team = $tree->createUnit('Team', $this->dept);
        $other = $tree->createUnit('Other', $company);
        $em = $I->grabEntityManager();

        foreach ([
            ['p_week', $team, MetricUnit::Ratio, MetricSourceType::PushApi, TimeAggregation::Sum, PeriodType::Week],
            ['p_month', $this->dept, MetricUnit::Count, MetricSourceType::PushApi, TimeAggregation::Sum, PeriodType::Month],
            ['p_manual', $this->dept, MetricUnit::Count, MetricSourceType::Manual, TimeAggregation::Sum, PeriodType::Week],
            ['p_formula', $this->dept, MetricUnit::Ratio, MetricSourceType::PushApi, TimeAggregation::Formula, PeriodType::Week],
            ['p_other', $other, MetricUnit::Count, MetricSourceType::PushApi, TimeAggregation::Sum, PeriodType::Week],
        ] as [$code, $unit, $unitType, $source, $agg, $granularity]) {
            $metric = new Metric($unit, $code, $code, $unitType);
            $metric->setSourceType($source)->setTimeAgg($agg)->setGranularity($granularity);
            $em->persist($metric);
        }

        [$token, $this->token] = IngestToken::issue('dept-script', $this->dept);
        $em->persist($token);
        $em->flush();

        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    public function pushedFactsLandInTheMetricPeriods(FunctionalTester $I): void
    {
        $this->push($I, $this->token, [
            ['metric' => 'p_week', 'date' => '2026-11-04', 'value' => '8.5'],
            ['metric' => 'p_month', 'date' => '2026-10-15', 'value' => 42],
        ]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['written' => 2, 'unchanged' => 0, 'removed' => 0]);
        $I->assertSame(
            ['p_month|month|2026-10-01|42|auto', 'p_week|week|2026-11-02|8.5|auto'],
            $this->stored($I),
            'any day maps to its week segment / month',
        );

        $run = $this->db($I)->fetchAssociative("SELECT scope, status, `rows` FROM sync_run WHERE source = 'push_api'");
        $I->assertSame(['scope' => 'dept-script', 'status' => 'success', 'rows' => 2], $run);
        $I->assertNotNull($this->db($I)->fetchOne("SELECT last_used_at FROM ingest_token WHERE name = 'dept-script'"));
    }

    public function repeatedPushIsUnchangedAndNullRemoves(FunctionalTester $I): void
    {
        $this->push($I, $this->token, [['metric' => 'p_week', 'date' => '2026-11-02', 'value' => 12.5]]);
        $this->push($I, $this->token, [['metric' => 'p_week', 'date' => '2026-11-05', 'value' => '12.50']]);
        $I->seeResponseContainsJson(['written' => 0, 'unchanged' => 1]);

        $this->push($I, $this->token, [['metric' => 'p_week', 'date' => '2026-11-03', 'value' => null]]);
        $I->seeResponseContainsJson(['removed' => 1]);
        $I->assertSame([], $this->stored($I));
    }

    public function oneInvalidItemRejectsTheWholeRequest(FunctionalTester $I): void
    {
        $this->push($I, $this->token, [
            ['metric' => 'p_week', 'date' => '2026-11-02', 'value' => '1'],
            ['metric' => 'p_manual', 'date' => '2026-11-02', 'value' => '1'],
            ['metric' => 'p_formula', 'date' => '2026-11-02', 'value' => '1'],
            ['metric' => 'p_other', 'date' => '2026-11-02', 'value' => '1'],
            ['metric' => 'nope', 'date' => '2026-11-02', 'value' => '1'],
            ['metric' => 'p_month', 'date' => '2026-11-31', 'value' => '1'],
            ['metric' => 'p_month', 'date' => '2099-01-01', 'value' => '1'],
            ['metric' => 'p_month', 'date' => '2026-10-01', 'value' => '1.5'],
            ['metric' => 'p_week', 'date' => '2026-11-08', 'value' => '2'],
        ]);

        $I->seeResponseCodeIs(422);
        $violations = json_decode($I->grabResponse(), true)['violations'];
        $I->assertSame(
            ['values[1].metric', 'values[2].metric', 'values[3].metric', 'values[4].metric', 'values[5].date', 'values[6].date', 'values[7].value', 'values[8]'],
            array_keys($violations),
            'manual, formula, foreign and missing metrics look the same; 08.11 is the same segment as 02.11',
        );
        $I->assertSame([], $this->stored($I), 'nothing written');
    }

    public function bodyMustHoldAListOfValues(FunctionalTester $I): void
    {
        $this->push($I, $this->token, []);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['violations' => ['values' => ['A list of 1 to 500 items is required.']]]);
    }

    public function onlyAValidActiveSourceTokenIsAccepted(FunctionalTester $I): void
    {
        $item = [['metric' => 'p_week', 'date' => '2026-11-02', 'value' => '1']];

        $I->sendPost('/api/ingest/metric-values', ['values' => $item]);
        $I->seeResponseCodeIs(401);

        $this->push($I, IngestToken::PREFIX.str_repeat('0', 48), $item);
        $I->seeResponseCodeIs(401);

        // A user JWT is not a source token.
        $I->haveUser('head@ingest.local');
        $jwt = $I->amLoggedInAs('head@ingest.local');
        $this->push($I, $jwt, $item);
        $I->seeResponseCodeIs(401);

        // A source token opens nothing outside /api/ingest.
        $I->amBearerAuthenticated($this->token);
        $I->sendGet('/api/org-units/'.$this->dept->getId().'/rnp');
        $I->seeResponseCodeIs(401);

        $em = $I->grabEntityManager();
        $em->getRepository(IngestToken::class)->findOneBy(['name' => 'dept-script'])?->revoke();
        $em->flush();
        $this->push($I, $this->token, $item);
        $I->seeResponseCodeIs(401);
        $I->seeResponseContainsJson(['error' => 'Invalid or revoked ingest token.']);
    }

    public function tokenCommandIssuesListsAndRevokes(FunctionalTester $I): void
    {
        $output = $I->runSymfonyConsoleCommand('app:ingest-token', ['action' => 'create', 'name' => 'cli-script', 'unit' => (int) $this->dept->getId()]);
        $I->assertSame(1, preg_match('/(rnp_[0-9a-f]{48})/', $output, $m), 'the token is printed once');
        $token = $m[1] ?? '';

        $this->push($I, $token, [['metric' => 'p_week', 'date' => '2026-11-02', 'value' => '3']]);
        $I->seeResponseCodeIs(200);

        $list = $I->runSymfonyConsoleCommand('app:ingest-token', ['action' => 'list']);
        $I->assertStringContainsString('cli-script', $list);
        $I->assertStringNotContainsString($token, $list, 'list never shows the full token');

        $I->runSymfonyConsoleCommand('app:ingest-token', ['action' => 'revoke', 'name' => 'cli-script']);
        $this->push($I, $token, [['metric' => 'p_week', 'date' => '2026-11-02', 'value' => '3']]);
        $I->seeResponseCodeIs(401);
    }

    public function adminsManageTokensThroughTheApi(FunctionalTester $I): void
    {
        $I->haveUser('boss@ingest.local', 'Boss', ['ROLE_ADMIN']);
        $I->haveUser('head@ingest.local');

        $I->amLoggedInAs('head@ingest.local');
        $I->sendGet('/api/admin/ingest-tokens');
        $I->seeResponseCodeIs(403);

        $I->amLoggedInAs('boss@ingest.local');
        $I->sendPost('/api/admin/ingest-tokens', ['name' => 'Bad Name', 'orgUnitId' => $this->dept->getId()]);
        $I->seeResponseCodeIs(422);
        $I->sendPost('/api/admin/ingest-tokens', ['name' => 'ui-script', 'orgUnitId' => $this->dept->getId()]);
        $I->seeResponseCodeIs(201);
        /** @var array{token: string, item: array{id: int, hint: string}} $created */
        $created = json_decode($I->grabResponse(), true, 512, \JSON_THROW_ON_ERROR);
        $I->assertMatchesRegularExpression('/^rnp_[0-9a-f]{48}$/', $created['token']);
        $I->assertSame(substr($created['token'], 0, 12).'…', $created['item']['hint']);

        $I->sendGet('/api/admin/ingest-tokens');
        $I->dontSeeResponseContains($created['token']);
        $I->seeResponseContainsJson(['name' => 'ui-script', 'orgUnitName' => 'Dept', 'revokedAt' => null]);

        $this->push($I, $created['token'], [['metric' => 'p_week', 'date' => '2026-11-02', 'value' => '1']]);
        $I->seeResponseCodeIs(200);

        $I->amLoggedInAs('boss@ingest.local');
        $I->sendPost('/api/admin/ingest-tokens/'.$created['item']['id'].'/revoke');
        $I->seeResponseCodeIs(200);
        $this->push($I, $created['token'], [['metric' => 'p_week', 'date' => '2026-11-02', 'value' => '1']]);
        $I->seeResponseCodeIs(401);
    }

    /**
     * @param list<array<string, mixed>> $values
     */
    private function push(FunctionalTester $I, string $token, array $values): void
    {
        $I->amBearerAuthenticated($token);
        $I->sendPost('/api/ingest/metric-values', ['values' => $values]);
    }

    /**
     * @return list<string> "code|period type|start|value|origin"
     */
    private function stored(FunctionalTester $I): array
    {
        $rows = $this->db($I)->fetchAllAssociative('SELECT m.code, v.period_type, v.period_start, v.value, v.origin FROM metric_value v JOIN metric m ON m.id = v.metric_id ORDER BY m.code');

        return array_map(static function (array $r): string {
            $value = rtrim(rtrim((string) $r['value'], '0'), '.');

            return implode('|', [$r['code'], $r['period_type'], $r['period_start'], $value, $r['origin']]);
        }, $rows);
    }

    private function db(FunctionalTester $I): Connection
    {
        /** @var Connection $connection */
        $connection = $I->grabService('doctrine.dbal.default_connection');

        return $connection;
    }
}
