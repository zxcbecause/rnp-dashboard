<?php

declare(strict_types=1);

namespace App\Integration\Platform\Sync;

use App\Integration\Platform\PlatformApiClient;
use App\Integration\Raw\RawUpserter;
use App\Integration\Raw\RawValue;

/**
 * GET /api/tickets → raw_platform_ticket. Same window strategy as housekeeping tasks.
 */
final readonly class TicketSyncer implements PlatformResourceSyncer
{
    private const string WINDOW = '-60 days';

    public function __construct(
        private PlatformApiClient $client,
        private RawUpserter $upserter,
    ) {
    }

    public static function resource(): string
    {
        return 'tickets';
    }

    public function sync(?\DateTimeImmutable $since): int
    {
        // The platform accepts only "Y-m-d H:i:s" here (ISO 8601 and bare dates are rejected as invalid).
        $query = null === $since ? [] : ['dateFrom' => $since->modify(self::WINDOW)->format('Y-m-d H:i:s')];

        return $this->upserter->upsert(self::rawTable(), $this->rows($this->client->paginate('/api/tickets', $query)));
    }

    /**
     * @param iterable<array<string, mixed>> $items
     *
     * @return \Generator<array<string, scalar|null>>
     */
    private function rows(iterable $items): \Generator
    {
        $now = RawValue::now();

        foreach ($items as $item) {
            $id = RawValue::int($item, 'id');
            if (null === $id) {
                continue;
            }

            yield [
                'id' => $id,
                'title' => RawValue::string($item, 'title') ?? '',
                'status' => RawValue::string($item, 'status', 16) ?? 'unknown',
                'type' => RawValue::string($item, 'type', 16) ?? 'unknown',
                'priority' => RawValue::string($item, 'priority', 16) ?? 'unknown',
                'accommodation_id' => RawValue::int($item, 'accommodationId'),
                'price' => RawValue::decimal($item, 'price'),
                'creation_date' => RawValue::datetime($item, 'creationDate'),
                'updated_at' => RawValue::datetime($item, 'updatedAt'),
                'payload' => RawValue::json($item),
                'synced_at' => $now,
            ];
        }
    }

    public static function rawTable(): string
    {
        return 'raw_platform_ticket';
    }
}
