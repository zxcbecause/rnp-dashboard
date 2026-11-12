<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integration\Platform;

use App\Integration\Platform\PlatformApiClient;
use App\Integration\Platform\PlatformApiException;
use Codeception\Test\Unit;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PlatformApiClientTest extends Unit
{
    /** @var list<string> "METHOD path?query" of every request */
    private array $log = [];

    private ?string $jwt = null;

    public function testPaginatesThroughAllPagesWithBearerToken(): void
    {
        $client = $this->client(function (string $method, string $url, array $options): MockResponse {
            if (str_ends_with($url, '/api/login')) {
                return $this->login();
            }

            $this->assertContains('Authorization: Bearer '.$this->jwt(), $options['headers']);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $page = (int) $query['page'];

            return $this->json([
                'items' => [['id' => $page * 10], ['id' => $page * 10 + 1]],
                'page' => $page, 'pagesTotal' => 3, 'pageSize' => 2, 'itemsCount' => 2, 'itemsTotal' => 6,
            ]);
        });

        $ids = array_column(iterator_to_array($client->paginate('/api/bookings', ['status' => 'PAID', 'portal' => null]), false), 'id');

        self::assertSame([10, 11, 20, 21, 30, 31], $ids);
        self::assertSame('POST /api/login', $this->log[0]);
        self::assertCount(4, $this->log, 'one login, three pages');
        self::assertStringContainsString('status=PAID', $this->log[1]);
        self::assertStringContainsString('size='.PlatformApiClient::PAGE_SIZE, $this->log[1]);
        self::assertStringNotContainsString('portal', $this->log[1], 'null filters are dropped');
    }

    public function testRetriesServerErrorsAndNetworkFailures(): void
    {
        $failures = [new MockResponse('', ['http_code' => 503]), new MockResponse('', ['error' => 'connection reset'])];
        $client = $this->client(function (string $method, string $url) use (&$failures): MockResponse {
            if (str_ends_with($url, '/api/login')) {
                return $this->login();
            }

            return array_shift($failures) ?? $this->json(['items' => [], 'page' => 1, 'pagesTotal' => 1]);
        });

        self::assertSame([], iterator_to_array($client->paginate('/api/tickets')));
        self::assertCount(4, $this->log, 'login + 503 + network error + success');
    }

    public function testGivesUpAfterMaxAttempts(): void
    {
        $client = $this->client(fn (string $method, string $url): MockResponse => str_ends_with($url, '/api/login')
            ? $this->login()
            : new MockResponse('', ['http_code' => 502]));

        $this->expectException(PlatformApiException::class);
        $this->expectExceptionMessage('failed after 4 attempts: HTTP 502');

        $client->get('/api/bookings');
    }

    public function testLogsInAgainOnceAfter401(): void
    {
        $answers = [new MockResponse('', ['http_code' => 401]), $this->json(['ok' => true])];
        $client = $this->client(function (string $method, string $url) use (&$answers): MockResponse {
            return str_ends_with($url, '/api/login') ? $this->login() : array_shift($answers) ?? new MockResponse('', ['http_code' => 500]);
        });

        self::assertSame(['ok' => true], $client->get('/api/accommodations'));
        self::assertSame(['POST /api/login', 'GET /api/accommodations', 'POST /api/login', 'GET /api/accommodations'], $this->log);
    }

    public function testPersistent401Fails(): void
    {
        $client = $this->client(fn (string $method, string $url): MockResponse => str_ends_with($url, '/api/login')
            ? $this->login()
            : new MockResponse('', ['http_code' => 401]));

        $this->expectException(PlatformApiException::class);
        $this->expectExceptionMessage('HTTP 401');

        $client->get('/api/bookings');
    }

    public function testClientErrorIsNotRetried(): void
    {
        $client = $this->client(fn (string $method, string $url): MockResponse => str_ends_with($url, '/api/login')
            ? $this->login()
            : new MockResponse('{"error":"bad"}', ['http_code' => 422]));

        try {
            $client->get('/api/bookings');
            self::fail('Expected an exception.');
        } catch (PlatformApiException $e) {
            self::assertSame(422, $e->statusCode);
            self::assertCount(2, $this->log);
        }
    }

    public function testFailedLoginNeverLeaksThePassword(): void
    {
        $client = $this->client(fn (): MockResponse => new MockResponse('{"code":401,"message":"Invalid credentials."}', ['http_code' => 401]));

        try {
            $client->get('/api/bookings');
            self::fail('Expected an exception.');
        } catch (PlatformApiException $e) {
            self::assertSame('Platform API login failed: HTTP 401.', $e->getMessage());
            self::assertStringNotContainsString('s3cret', $e->getMessage());
        }
    }

    public function testNotConfiguredClientRefusesToCall(): void
    {
        $client = new PlatformApiClient(new MockHttpClient([]), new NullLogger(), '', 'dashboard_client', '', 0);

        self::assertFalse($client->isConfigured());
        $this->expectException(PlatformApiException::class);
        $this->expectExceptionMessage('not configured');

        $client->get('/api/bookings');
    }

    public function testReusesTokenUntilItIsAboutToExpire(): void
    {
        $client = $this->client(fn (string $method, string $url): MockResponse => str_ends_with($url, '/api/login')
            ? $this->login()
            : $this->json(['ok' => true]));

        $client->get('/api/a');
        $client->get('/api/b');

        self::assertSame(['POST /api/login', 'GET /api/a', 'GET /api/b'], $this->log);
    }

    private function client(callable $factory): PlatformApiClient
    {
        $this->log = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($factory): MockResponse {
            $parts = parse_url($url);
            $this->log[] = $method.' '.($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');

            return $factory($method, $url, $options);
        });

        return new PlatformApiClient($http, new NullLogger(), 'http://platform.test/', 'dashboard_client', 's3cret', 0);
    }

    private function login(): MockResponse
    {
        return $this->json(['token' => $this->jwt(), 'refreshToken' => 'r']);
    }

    private function jwt(): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode(['exp' => time() + 900])), '+/', '-_'), '=');

        return $this->jwt ??= 'header.'.$payload.'.signature';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
