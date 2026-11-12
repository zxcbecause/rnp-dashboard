<?php

declare(strict_types=1);

namespace App\Integration\Bitrix\Sync;

use App\Integration\Bitrix\BitrixClient;
use App\Integration\Raw\RawUpserter;
use App\Integration\Raw\RawValue;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * crm.status.list → raw_bitrix_deal_stage: the stages of every deal pipeline with their order.
 * A small dictionary, always read in full.
 */
#[AsTaggedItem(priority: 30)]
final readonly class DealStageSyncer implements BitrixResourceSyncer
{
    public function __construct(
        private BitrixClient $client,
        private RawUpserter $upserter,
    ) {
    }

    public static function resource(): string
    {
        return 'deal_stages';
    }

    public function sync(?\DateTimeImmutable $since): int
    {
        return $this->upserter->upsert(self::rawTable(), $this->rows($this->client->listAll('crm.status.list', ['order' => ['ID' => 'ASC']])));
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
            $entity = RawValue::string($item, 'ENTITY_ID', 64);
            $id = RawValue::string($item, 'STATUS_ID', 64);
            // DEAL_STAGE = default pipeline 0, DEAL_STAGE_<n> = pipeline n; everything else is another dictionary.
            if (null === $id || null === $entity || 1 !== preg_match('/^DEAL_STAGE(?:_(\d+))?$/', $entity, $m)) {
                continue;
            }

            $semantics = RawValue::string($item, 'SEMANTICS', 1);

            yield [
                'id' => $id,
                'category_id' => (int) ($m[1] ?? 0),
                'name' => RawValue::string($item, 'NAME'),
                'sort' => RawValue::int($item, 'SORT') ?? 0,
                'semantics' => \in_array($semantics, ['S', 'F'], true) ? $semantics : null,
                'payload' => RawValue::json($item),
                'synced_at' => $now,
            ];
        }
    }

    public static function rawTable(): string
    {
        return 'raw_bitrix_deal_stage';
    }
}
