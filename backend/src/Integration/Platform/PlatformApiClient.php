<?php

declare(strict_types=1);

namespace App\Integration\Platform;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Read-only client of the Acme platform API, authenticated as the machine account
 * (dashboard_client: ROLE_MACHINE + ROLE_REVENUE_MANAGER).
 *
 *  - JWT from POST /api/login, renewed shortly before expiry and once more on a 401;
 *  - paginated lists (page/size → items, page, pagesTotal, itemsTotal);
 *  - retries with backoff on network errors, 429 and 5xx (Retry-After honoured).
 */
class PlatformApiClient
{
    public const int PAGE_SIZE = 100;
    private const int MAX_ATTEMPTS = 4;
    private const int MAX_PAGES = 5000;
    private const int TOKEN_RENEW_MARGIN = 60;

    private ?string $token = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(PLATFORM_API_URL)%')]
        private readonly string $baseUrl,
        #[Autowire('%env(PLATFORM_API_USERNAME)%')]
        private readonly string $username,
        #[Autowire('%env(PLATFORM_API_PASSWORD)%')]
        private readonly string $password,
        #[Autowire('%app.platform_api.retry_delay_ms%')]
        private readonly int $retryDelayMs = 500,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->baseUrl) && '' !== $this->username && '' !== $this->password;
    }

    /**
     * Iterates over every item of a paginated list endpoint.
     *
     * @param array<string, scalar|null> $query filters; null values are dropped
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function paginate(string $path, array $query = []): \Generator
    {
        $query = array_filter($query, static fn ($value): bool => null !== $value);

        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $data = $this->get($path, [...$query, 'page' => $page, 'size' => self::PAGE_SIZE]);

            $items = $data['items'] ?? null;
            if (!\is_array($items)) {
                throw new PlatformApiException(\sprintf('Unexpected list response from %s: no "items".', $path));
            }

            foreach ($items as $item) {
                if (\is_array($item)) {
                    /* @var array<string, mixed> $item */
                    yield $item;
                }
            }

            $pagesTotal = \is_int($data['pagesTotal'] ?? null) ? $data['pagesTotal'] : 0;
            if ([] === $items || $page >= $pagesTotal) {
                return;
            }
        }

        throw new PlatformApiException(\sprintf('Pagination of %s exceeded %d pages.', $path, self::MAX_PAGES));
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $reloggedIn = false;

        for ($attempt = 1;; ++$attempt) {
            $token = $this->token();

            try {
                $response = $this->httpClient->request('GET', $this->url($path), [
                    'auth_bearer' => $token,
                    'query' => $query,
                    'headers' => ['Accept' => 'application/json'],
                    'timeout' => 30,
                ]);
                $status = $response->getStatusCode();
            } catch (TransportExceptionInterface $e) {
                $this->retryOrThrow($attempt, $path, null, null, $e);
                continue;
            }

            if (401 === $status && !$reloggedIn) {
                // Token revoked or expired early: log in once more, then give up.
                $reloggedIn = true;
                $this->token = null;
                continue;
            }

            if (429 === $status || $status >= 500) {
                $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
                $this->retryOrThrow($attempt, $path, $status, is_numeric($retryAfter) ? (int) $retryAfter : null);
                continue;
            }

            if (200 !== $status) {
                throw new PlatformApiException(\sprintf('GET %s answered HTTP %d.', $path, $status), $status);
            }

            /** @var array<string, mixed> $data */
            $data = $response->toArray(false);

            return $data;
        }
    }

    private function token(): string
    {
        if (null !== $this->token && time() < $this->tokenExpiresAt - self::TOKEN_RENEW_MARGIN) {
            return $this->token;
        }

        if (!$this->isConfigured()) {
            throw new PlatformApiException('Platform API is not configured (PLATFORM_API_URL / _USERNAME / _PASSWORD).');
        }

        try {
            $response = $this->httpClient->request('POST', $this->url('/api/login'), [
                'json' => ['username' => $this->username, 'password' => $this->password],
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            $data = 200 === $status ? $response->toArray(false) : [];
        } catch (TransportExceptionInterface $e) {
            throw new PlatformApiException('Platform API login failed: '.$e->getMessage(), null, $e);
        }

        $token = $data['token'] ?? null;
        if (200 !== $status || !\is_string($token) || '' === $token) {
            throw new PlatformApiException(\sprintf('Platform API login failed: HTTP %d.', $status), $status);
        }

        $this->token = $token;
        $this->tokenExpiresAt = $this->expiryOf($token);

        return $token;
    }

    /**
     * Reads "exp" from the JWT payload; falls back to the platform's 15 minute lifetime.
     */
    private function expiryOf(string $jwt): int
    {
        $parts = explode('.', $jwt);
        $payload = isset($parts[1]) ? base64_decode(strtr($parts[1], '-_', '+/'), true) : false;
        $claims = false !== $payload ? json_decode($payload, true) : null;

        return \is_array($claims) && \is_int($claims['exp'] ?? null) ? $claims['exp'] : time() + 900;
    }

    private function retryOrThrow(int $attempt, string $path, ?int $status, ?int $retryAfter, ?\Throwable $previous = null): void
    {
        $reason = null !== $status ? 'HTTP '.$status : 'network error';

        if ($attempt >= self::MAX_ATTEMPTS) {
            throw new PlatformApiException(\sprintf('GET %s failed after %d attempts: %s.', $path, $attempt, $reason), $status, $previous);
        }

        $delayMs = null !== $retryAfter ? min($retryAfter, 30) * 1000 : $this->retryDelayMs * (2 ** ($attempt - 1));
        $this->logger->warning('Platform API {path}: {reason}, retry {attempt} in {delay} ms.', [
            'path' => $path, 'reason' => $reason, 'attempt' => $attempt, 'delay' => $delayMs,
        ]);

        if ($delayMs > 0 && $this->retryDelayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
