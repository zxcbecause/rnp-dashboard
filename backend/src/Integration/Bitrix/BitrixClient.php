<?php

declare(strict_types=1);

namespace App\Integration\Bitrix;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Read-only client of the Bitrix24 REST API through an incoming webhook (scope crm).
 *
 *  - at most ~2 requests per second (the portal's limit), enforced by spacing the requests;
 *  - lists are read page by page (50 rows, `start`), the remaining pages in `batch` calls of up to 50 pages;
 *  - retries with backoff on network errors, 5xx, 429 and QUERY_LIMIT_EXCEEDED; other errors fail at once;
 *  - the webhook URL holds the access token, so it never appears in messages or logs.
 */
class BitrixClient
{
    public const int PAGE_SIZE = 50;
    private const int BATCH_SIZE = 50;
    private const int MAX_ATTEMPTS = 5;
    private const int MAX_ROWS = 1_000_000;
    private const array RETRYABLE_ERRORS = ['QUERY_LIMIT_EXCEEDED', 'OPERATION_TIME_LIMIT', 'INTERNAL_SERVER_ERROR'];

    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(BITRIX_WEBHOOK_URL)%')]
        private readonly string $webhookUrl,
        #[Autowire('%app.bitrix.min_interval_ms%')]
        private readonly int $minIntervalMs = 500,
        #[Autowire('%app.bitrix.retry_delay_ms%')]
        private readonly int $retryDelayMs = 1000,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->webhookUrl);
    }

    /**
     * One REST call; returns the whole response body (result, total, next, ...).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        if (!$this->isConfigured()) {
            throw new BitrixApiException('Bitrix24 is not configured (BITRIX_WEBHOOK_URL).');
        }

        for ($attempt = 1;; ++$attempt) {
            $this->throttle();

            try {
                $response = $this->httpClient->request('POST', $this->url($method), ['json' => (object) $params, 'timeout' => 60]);
                $status = $response->getStatusCode();
                $data = $response->toArray(false);
            } catch (HttpClientException $e) {
                $this->retryOrThrow($attempt, $method, 'network error: '.$this->redact($e->getMessage()), null, $e);
                continue;
            }

            $error = \is_string($data['error'] ?? null) ? $data['error'] : null;
            if (null === $error && 200 === $status) {
                /* @var array<string, mixed> $data */
                return $data;
            }

            $reason = \sprintf('HTTP %d%s', $status, null !== $error ? ' '.$error.$this->description($data) : '');
            if ($status >= 500 || 429 === $status || \in_array($error, self::RETRYABLE_ERRORS, true)) {
                $this->retryOrThrow($attempt, $method, $reason, $error);
                continue;
            }

            throw new BitrixApiException(\sprintf('Bitrix24 %s failed: %s.', $method, $reason), $error);
        }
    }

    /**
     * Every row of a list method (crm.deal.list, crm.activity.list, crm.stagehistory.list, ...).
     * The first page tells the total; the rest is fetched in batches. Pass a stable "order" (by ID):
     * offsets over an unordered list may skip or repeat rows.
     *
     * @param array<string, mixed> $params
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function listAll(string $method, array $params = []): \Generator
    {
        $first = $this->call($method, [...$params, 'start' => 0]);
        yield from $this->rows($first['result'] ?? null, $method);

        $next = \is_int($first['next'] ?? null) ? $first['next'] : null;
        if (null === $next) {
            return;
        }

        if (!\is_int($first['total'] ?? null)) {
            // No total to plan batches with: follow "next" page by page.
            for ($page = $first; \is_int($page['next'] ?? null) && $page['next'] < self::MAX_ROWS;) {
                $page = $this->call($method, [...$params, 'start' => $page['next']]);
                yield from $this->rows($page['result'] ?? null, $method);
            }

            return;
        }

        $total = min($first['total'], self::MAX_ROWS);
        for ($chunk = $next; $chunk < $total; $chunk += self::PAGE_SIZE * self::BATCH_SIZE) {
            $commands = [];
            for ($start = $chunk; $start < min($total, $chunk + self::PAGE_SIZE * self::BATCH_SIZE); $start += self::PAGE_SIZE) {
                $commands['p'.$start] = $method.'?'.http_build_query([...$params, 'start' => $start]);
            }

            $results = $this->batch($method, $commands);
            foreach (array_keys($commands) as $key) {
                yield from $this->rows($results[$key] ?? null, $method);
            }
        }
    }

    /**
     * List results come either as a plain list or as {"items": [...]} (crm.stagehistory.list, crm.item.list).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function rows(mixed $result, string $method): \Generator
    {
        if (\is_array($result) && \is_array($result['items'] ?? null)) {
            $result = $result['items'];
        }
        if (!\is_array($result) || !array_is_list($result)) {
            throw new BitrixApiException(\sprintf('Unexpected list response from %s.', $method));
        }

        foreach ($result as $row) {
            if (\is_array($row)) {
                /* @var array<string, mixed> $row */
                yield $row;
            }
        }
    }

    private function throttle(): void
    {
        if ($this->minIntervalMs <= 0) {
            return;
        }

        $wait = $this->lastRequestAt + $this->minIntervalMs / 1000 - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequestAt = microtime(true);
    }

    private function retryOrThrow(int $attempt, string $method, string $reason, ?string $errorCode, ?\Throwable $previous = null): void
    {
        if ($attempt >= self::MAX_ATTEMPTS) {
            // The previous exception is dropped on purpose: its message may contain the webhook URL.
            throw new BitrixApiException(\sprintf('Bitrix24 %s failed after %d attempts: %s.', $method, $attempt, $reason), $errorCode ?? (null !== $previous ? 'NETWORK' : null));
        }

        $delayMs = $this->retryDelayMs * (2 ** ($attempt - 1));
        $this->logger->warning('Bitrix24 {method}: {reason}, retry {attempt} in {delay} ms.', [
            'method' => $method, 'reason' => $reason, 'attempt' => $attempt, 'delay' => $delayMs,
        ]);

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function description(array $data): string
    {
        $description = $data['error_description'] ?? null;

        return \is_string($description) && '' !== $description ? ' ('.mb_substr($this->redact($description), 0, 300).')' : '';
    }

    private function redact(string $text): string
    {
        $base = rtrim(trim($this->webhookUrl), '/');

        return '' === $base ? $text : str_replace($base, '<webhook>', $text);
    }

    private function url(string $method): string
    {
        return rtrim(trim($this->webhookUrl), '/').'/'.$method.'.json';
    }

    /**
     * One batch of list pages. A rate-limit error inside the batch (result_error) is retried like a
     * failed request; any other error fails the list.
     *
     * @param array<string, string> $commands
     *
     * @return array<mixed> command key => result
     */
    private function batch(string $method, array $commands): array
    {
        for ($attempt = 1;; ++$attempt) {
            $batch = $this->call('batch', ['halt' => 1, 'cmd' => $commands]);
            $results = $batch['result']['result'] ?? null;
            $errors = $batch['result']['result_error'] ?? [];

            if (\is_array($errors) && [] !== $errors) {
                $firstError = reset($errors);
                $code = \is_array($firstError) && \is_string($firstError['error'] ?? null) ? $firstError['error'] : 'unknown';
                if (\in_array($code, self::RETRYABLE_ERRORS, true)) {
                    $this->retryOrThrow($attempt, 'batch of '.$method, $code, $code);
                    continue;
                }

                throw new BitrixApiException(\sprintf('Bitrix24 batch of %s failed: %s.', $method, $code), $code);
            }
            if (!\is_array($results)) {
                throw new BitrixApiException(\sprintf('Unexpected batch response for %s: no "result".', $method));
            }

            return $results;
        }
    }
}
