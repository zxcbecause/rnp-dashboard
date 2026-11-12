<?php

declare(strict_types=1);

namespace App\Tests\Support\Bitrix;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * In-memory Bitrix24 REST API for functional tests, plugged into test.bitrix_http_client.
 * Serves list methods (50 rows per page, "start", select, simple filters) and "batch".
 */
final class FakeBitrixApi
{
    public const int PAGE_SIZE = 50;

    /** @var array<string, list<array<string, mixed>>> method => rows */
    public array $lists = [];

    /** @var array<string, array<string, mixed>> method => fixed "result" (non-list methods) */
    public array $results = [];

    /** @var array<string, list<array{int, string}>> method => queued failures [HTTP status, error code] */
    public array $failures = [];

    /** @var list<array{method: string, params: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        $restMethod = (string) preg_replace('#^.*/([^/]+)\.json$#', '$1', (string) parse_url($url, \PHP_URL_PATH));
        $params = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '{}', true);
        $params = \is_array($params) ? $params : [];
        $this->requests[] = ['method' => $restMethod, 'params' => $params];

        if ([] !== ($this->failures[$restMethod] ?? [])) {
            [$status, $error] = array_shift($this->failures[$restMethod]);

            return new MockResponse((string) json_encode(['error' => $error, 'error_description' => 'scripted failure']), ['http_code' => $status]);
        }

        if ('batch' === $restMethod) {
            return $this->json(['result' => $this->batch(\is_array($params['cmd'] ?? null) ? $params['cmd'] : [])]);
        }

        return $this->json($this->answer($restMethod, $params));
    }

    /**
     * @return list<array<string, mixed>> params of every call of the method, batch commands included
     */
    public function paramsOf(string $method): array
    {
        $out = [];
        foreach ($this->requests as $request) {
            if ($request['method'] === $method) {
                $out[] = $request['params'];
            }
            if ('batch' === $request['method'] && \is_array($request['params']['cmd'] ?? null)) {
                foreach ($request['params']['cmd'] as $command) {
                    [$m, $query] = explode('?', (string) $command, 2) + [1 => ''];
                    if ($m === $method) {
                        parse_str($query, $p);
                        /** @var array<string, mixed> $parsed */
                        $parsed = $p;
                        $out[] = $parsed;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function answer(string $method, array $params): array
    {
        if (!isset($this->lists[$method])) {
            return ['result' => $this->results[$method] ?? []];
        }

        $rows = array_values(array_filter($this->lists[$method], fn (array $row): bool => $this->matches($row, \is_array($params['filter'] ?? null) ? $params['filter'] : [])));
        $start = (int) ($params['start'] ?? 0);
        $page = \array_slice($rows, $start, self::PAGE_SIZE);

        $select = \is_array($params['select'] ?? null) ? $params['select'] : [];
        if ([] !== $select) {
            $page = array_map(static fn (array $row): array => array_intersect_key($row, array_flip($select)), $page);
        }

        $answer = ['result' => 'crm.stagehistory.list' === $method ? ['items' => $page] : $page, 'total' => \count($rows)];
        if ($start + self::PAGE_SIZE < \count($rows)) {
            $answer['next'] = $start + self::PAGE_SIZE;
        }

        return $answer;
    }

    /**
     * @param array<mixed> $commands
     *
     * @return array<string, mixed>
     */
    private function batch(array $commands): array
    {
        $result = ['result' => [], 'result_error' => [], 'result_total' => [], 'result_next' => []];
        foreach ($commands as $key => $command) {
            [$method, $query] = explode('?', (string) $command, 2) + [1 => ''];
            parse_str($query, $params);
            /** @var array<string, mixed> $params */
            $answer = $this->answer($method, $params);
            $result['result'][$key] = $answer['result'];
            $result['result_total'][$key] = $answer['total'] ?? null;
            if (isset($answer['next'])) {
                $result['result_next'][$key] = $answer['next'];
            }
        }

        return $result;
    }

    /**
     * Supports "FIELD" (equals), "@FIELD" (in list), ">FIELD", ">=FIELD", "<FIELD", "<=FIELD".
     *
     * @param array<string, mixed> $row
     * @param array<mixed>         $filter
     */
    private function matches(array $row, array $filter): bool
    {
        foreach ($filter as $key => $expected) {
            if (1 !== preg_match('/^(@|>=|<=|>|<)?(.+)$/', (string) $key, $m)) {
                return false;
            }
            $actual = $row[$m[2]] ?? null;

            $ok = match ($m[1]) {
                '@' => \in_array((string) $actual, array_map('strval', (array) $expected), true),
                '>' => $this->compare($actual, $expected) > 0,
                '>=' => $this->compare($actual, $expected) >= 0,
                '<' => $this->compare($actual, $expected) < 0,
                '<=' => $this->compare($actual, $expected) <= 0,
                default => (string) $actual === (string) $expected,
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    private function compare(mixed $actual, mixed $expected): int
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return $actual <=> $expected;
        }

        return strtotime((string) $actual) <=> strtotime((string) $expected);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
