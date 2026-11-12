<?php

declare(strict_types=1);

namespace App\Integration\Bitrix\Sync;

use App\Integration\Bitrix\BitrixClient;
use App\Integration\Raw\RawUpserter;
use App\Integration\Raw\RawValue;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * crm.stagehistory.list (deals of the owner pipelines) → raw_bitrix_deal_stage_history.
 * The history is append-only, so incremental runs ask only for ids above the stored maximum.
 */
#[AsTaggedItem(priority: 10)]
final readonly class DealStageHistorySyncer implements BitrixResourceSyncer
{
    private const int ENTITY_TYPE_DEAL = 2;

    public function __construct(
        private BitrixClient $client,
        private RawUpserter $upserter,
        private Connection $connection,
    ) {
    }

    public static function resource(): string
    {
        return 'deal_stage_history';
    }

    public function sync(?\DateTimeImmutable $since): int
    {
        $filter = ['@CATEGORY_ID' => self::OWNER_PIPELINES];
        $lastId = null === $since ? false : $this->connection->fetchOne('SELECT MAX(id) FROM raw_bitrix_deal_stage_history');
        if (is_numeric($lastId)) {
            $filter['>ID'] = (int) $lastId;
        }

        $items = $this->client->listAll('crm.stagehistory.list', [
            'entityTypeId' => self::ENTITY_TYPE_DEAL,
            'select' => ['ID', 'TYPE_ID', 'OWNER_ID', 'CREATED_TIME', 'CATEGORY_ID', 'STAGE_ID', 'STAGE_SEMANTIC_ID'],
            'filter' => $filter,
            'order' => ['ID' => 'ASC'],
        ]);

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
            $dealId = RawValue::int($item, 'OWNER_ID');
            if (null === $id || null === $dealId) {
                continue;
            }

            yield [
                'id' => $id,
                'deal_id' => $dealId,
                'category_id' => RawValue::int($item, 'CATEGORY_ID'),
                'stage_id' => RawValue::string($item, 'STAGE_ID', 64),
                'stage_semantic' => RawValue::string($item, 'STAGE_SEMANTIC_ID', 1),
                'type_id' => RawValue::int($item, 'TYPE_ID'),
                'created_time' => RawValue::datetime($item, 'CREATED_TIME'),
                'payload' => RawValue::json($item),
                'synced_at' => $now,
            ];
        }
    }

    public static function rawTable(): string
    {
        return 'raw_bitrix_deal_stage_history';
    }
}
