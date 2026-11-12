<?php

declare(strict_types=1);

namespace App\Integration\Bitrix\Sync;

use App\Integration\Bitrix\BitrixClient;
use App\Integration\Raw\RawUpserter;
use App\Integration\Raw\RawValue;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * crm.activity.list (TYPE_ID = 2, calls) → raw_bitrix_call. Incremental by LAST_UPDATED.
 * The webhook has no telephony scope: a missed call is the activity flag SETTINGS.MISSED_CALL.
 * SUBJECT/DESCRIPTION are not requested (they hold phone numbers).
 */
#[AsTaggedItem(priority: 0)]
final readonly class CallSyncer implements BitrixResourceSyncer
{
    private const int TYPE_CALL = 2;

    private const array SELECT = [
        'ID', 'DIRECTION', 'COMPLETED', 'START_TIME', 'END_TIME', 'OWNER_TYPE_ID', 'OWNER_ID',
        'RESPONSIBLE_ID', 'LAST_UPDATED', 'SETTINGS', 'PROVIDER_ID',
    ];

    public function __construct(
        private BitrixClient $client,
        private RawUpserter $upserter,
    ) {
    }

    public static function resource(): string
    {
        return 'calls';
    }

    public function sync(?\DateTimeImmutable $since): int
    {
        $filter = ['TYPE_ID' => self::TYPE_CALL];
        if (null !== $since) {
            $filter['>=LAST_UPDATED'] = $since->modify(self::OVERLAP)->format(\DATE_ATOM);
        }

        $items = $this->client->listAll('crm.activity.list', ['select' => self::SELECT, 'filter' => $filter, 'order' => ['ID' => 'ASC']]);

        return $this->upserter->upsert(self::rawTable(), $this->rows($items));
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
            $id = RawValue::int($item, 'ID');
            if (null === $id) {
                continue;
            }

            $settings = \is_array($item['SETTINGS'] ?? null) ? $item['SETTINGS'] : [];

            yield [
                'id' => $id,
                'direction' => RawValue::int($item, 'DIRECTION'),
                'missed' => true === ($settings['MISSED_CALL'] ?? null) || 'Y' === ($settings['MISSED_CALL'] ?? null) ? 1 : 0,
                'completed' => 'Y' === ($item['COMPLETED'] ?? null) ? 1 : 0,
                'start_time' => RawValue::datetime($item, 'START_TIME'),
                'end_time' => RawValue::datetime($item, 'END_TIME'),
                'owner_type_id' => RawValue::int($item, 'OWNER_TYPE_ID'),
                'owner_id' => RawValue::int($item, 'OWNER_ID'),
                'responsible_id' => RawValue::int($item, 'RESPONSIBLE_ID'),
                'last_updated' => RawValue::datetime($item, 'LAST_UPDATED'),
                'payload' => RawValue::json($item),
                'synced_at' => $now,
            ];
        }
    }

    public static function rawTable(): string
    {
        return 'raw_bitrix_call';
    }
}
