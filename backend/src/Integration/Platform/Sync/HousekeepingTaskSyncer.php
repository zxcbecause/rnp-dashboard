<?php

declare(strict_types=1);

namespace App\Integration\Platform\Sync;

use App\Integration\Platform\PlatformApiClient;
use App\Integration\Raw\RawUpserter;
use App\Integration\Raw\RawValue;

/**
 * GET /api/housekeeping-tasks → raw_platform_housekeeping_task.
 * The endpoint has no "modified since" filter: incremental runs re-read a due-date window,
 * the nightly full run catches older changes.
 */
final readonly class HousekeepingTaskSyncer implements PlatformResourceSyncer
{
    private const string WINDOW = '-60 days';

    public function __construct(
        private PlatformApiClient $client,
        private RawUpserter $upserter,
    ) {
    }

    public static function resource(): string
    {
        return 'housekeeping_tasks';
    }

    public function sync(?\DateTimeImmutable $since): int
    {
        // The platform requires ISO 8601 with an offset here (bare dates are rejected as invalid).
        $query = null === $since ? [] : ['dueDateFrom' => $since->modify(self::WINDOW)->format(\DATE_ATOM)];

        return $this->upserter->upsert(self::rawTable(), $this->rows($this->client->paginate('/api/housekeeping-tasks', $query)));
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

            $accommodation = $item['accommodation'] ?? null;

            yield [
                'id' => $id,
                'status' => RawValue::string($item, 'status', 16) ?? 'unknown',
                'type' => RawValue::string($item, 'type', 32) ?? 'unknown',
                'accommodation_id' => \is_array($accommodation) ? RawValue::int($accommodation, 'id') : null,
                'booking_id' => RawValue::int($item, 'bookingId'),
                'assignee_id' => RawValue::int($item, 'assigneeId'),
                'due_date' => RawValue::datetime($item, 'dueDate'),
                'creation_date' => RawValue::datetime($item, 'creationDate'),
                'payload' => RawValue::json($item),
                'synced_at' => $now,
            ];
        }
    }

    public static function rawTable(): string
    {
        return 'raw_platform_housekeeping_task';
    }
}
