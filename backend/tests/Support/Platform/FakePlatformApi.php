<?php

declare(strict_types=1);

namespace App\Tests\Support\Platform;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * In-memory Acme platform API for functional tests, plugged into test.platform_http_client.
 * Serves POST /api/login and paginated GET lists with the platform's response shape.
 */
final class FakePlatformApi
{
    /** @var array<string, list<array<string, mixed>>> path => items */
    public array $lists = [];

    /** @var array<string, int> path => HTTP status to fail with */
    public array $failures = [];

    /**
     * Detail cards of /api/accommodations/{id}: id => extra fields. By default a card repeats the list item
     * (the real list lacks the sales dates, so tests may strip them from the list and set them here).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $accommodationDetails = [];

    /**
     * Detail cards of /api/bookings/{id}: id => extra fields (rentalPrice, portalCommission...) merged over the list item.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $bookingDetails = [];

    /** @var list<array{path: string, query: array<int|string, mixed>}> */
    public array $requests = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $this->requests[] = ['path' => $path, 'query' => $query];

        if ('POST' === $method && '/api/login' === $path) {
            return $this->json(['token' => 'h.'.rtrim(base64_encode((string) json_encode(['exp' => time() + 900])), '=').'.s', 'refreshToken' => 'r']);
        }

        if (isset($this->failures[$path])) {
            return new MockResponse('{"error":"boom"}', ['http_code' => $this->failures[$path]]);
        }

        if (1 === preg_match('#^/api/accommodations/(\d+)$#', $path, $m)) {
            return $this->accommodationDetail((int) $m[1]);
        }

        if (1 === preg_match('#^/api/bookings/(\d+)$#', $path, $m)) {
            return $this->bookingDetail((int) $m[1]);
        }

        $status = \is_string($query['status'] ?? null) ? $query['status'] : null;
        $key = null !== $status && '/api/accommodations' === $path ? $path.'?status='.$status : $path;
        $items = $this->lists[$key] ?? [];
        $size = (int) ($query['size'] ?? 10);
        $page = (int) ($query['page'] ?? 1);

        return $this->json([
            'items' => \array_slice($items, ($page - 1) * $size, $size),
            'page' => $page,
            'pagesTotal' => max(1, (int) ceil(\count($items) / $size)),
            'pageSize' => $size,
            'itemsCount' => \count(\array_slice($items, ($page - 1) * $size, $size)),
            'itemsTotal' => \count($items),
        ]);
    }

    /**
     * @return list<array<int|string, mixed>> queries sent to the given path
     */
    public function queriesFor(string $path): array
    {
        return array_values(array_map(
            static fn (array $r): array => $r['query'],
            array_filter($this->requests, static fn (array $r): bool => $r['path'] === $path),
        ));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
    }

    private function accommodationDetail(int $id): MockResponse
    {
        foreach (['/api/accommodations', '/api/accommodations?status=DELETED'] as $key) {
            foreach ($this->lists[$key] ?? [] as $item) {
                if (($item['id'] ?? null) === $id) {
                    return $this->json([...$item, ...($this->accommodationDetails[$id] ?? [])]);
                }
            }
        }

        return new MockResponse('{"error":"Not found"}', ['http_code' => 404]);
    }

    private function bookingDetail(int $id): MockResponse
    {
        foreach ($this->lists['/api/bookings'] ?? [] as $item) {
            if (($item['id'] ?? null) === $id) {
                return $this->json([...$item, ...($this->bookingDetails[$id] ?? [])]);
            }
        }

        return new MockResponse('{"error":"Not found"}', ['http_code' => 404]);
    }
}
