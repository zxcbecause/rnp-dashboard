<?php

declare(strict_types=1);

namespace App\Integration\Bitrix\Sync;

use App\Integration\Bitrix\BitrixClient;
use App\Integration\Raw\RawUpserter;
use App\Integration\Raw\RawValue;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * crm.deal.list (owner pipelines) → raw_bitrix_deal. Incremental by DATE_MODIFY.
 * Only non-personal fields are requested; the "Source" enumeration is stored as its label.
 */
#[AsTaggedItem(priority: 20)]
final readonly class DealSyncer implements BitrixResourceSyncer
{
    /** Deal user field "Source" (enumeration: Marketing lead / Cold call). */
    public const string OWNER_SOURCE_FIELD = 'UF_CRM_1770044073839';

    private const array SELECT = [
        'ID', 'CATEGORY_ID', 'STAGE_ID', 'STAGE_SEMANTIC_ID', 'SOURCE_ID', 'UTM_SOURCE', 'ASSIGNED_BY_ID',
        'LEAD_ID', 'CLOSED', 'DATE_CREATE', 'DATE_MODIFY', 'CLOSEDATE', self::OWNER_SOURCE_FIELD,
    ];

    public function __construct(
        private BitrixClient $client,
        private RawUpserter $upserter,
    ) {
    }

    public static function resource(): string
    {
        return 'deals';
    }

    public function sync(?\DateTimeImmutable $since): int
    {
        $filter = ['@CATEGORY_ID' => self::OWNER_PIPELINES];
        if (null !== $since) {
            $filter['>=DATE_MODIFY'] = $since->modify(self::OVERLAP)->format(\DATE_ATOM);
        }

        $items = $this->client->listAll('crm.deal.list', ['select' => self::SELECT, 'filter' => $filter, 'order' => ['ID' => 'ASC']]);

        return $this->upserter->upsert(self::rawTable(), $this->rows($items, $this->ownerSourceLabels()));
    }

    /**
     * @param iterable<array<string, mixed>> $items
     * @param array<string, string>          $labels enumeration id => label
     *
     * @return \Generator<array<string, scalar|null>>
     */
    private function rows(iterable $items, array $labels): \Generator
    {
        $now = RawValue::now();

        foreach ($items as $item) {
            $id = RawValue::int($item, 'ID');
            if (null === $id) {
                continue;
            }

            $source = RawValue::string($item, self::OWNER_SOURCE_FIELD, 64);

            yield [
                'id' => $id,
                'category_id' => RawValue::int($item, 'CATEGORY_ID'),
                'stage_id' => RawValue::string($item, 'STAGE_ID', 64),
                'stage_semantic' => RawValue::string($item, 'STAGE_SEMANTIC_ID', 1),
                'source_id' => RawValue::string($item, 'SOURCE_ID', 128),
                'owner_source' => null === $source ? null : mb_substr($labels[$source] ?? $source, 0, 128),
                'utm_source' => RawValue::string($item, 'UTM_SOURCE'),
                'assigned_by_id' => RawValue::int($item, 'ASSIGNED_BY_ID'),
                'lead_id' => RawValue::int($item, 'LEAD_ID'),
                'closed' => 'Y' === ($item['CLOSED'] ?? null) ? 1 : 0,
                'date_create' => RawValue::datetime($item, 'DATE_CREATE'),
                'date_modify' => RawValue::datetime($item, 'DATE_MODIFY'),
                'payload' => RawValue::json($item),
                'synced_at' => $now,
            ];
        }
    }

    /**
     * @return array<string, string>
     */
    private function ownerSourceLabels(): array
    {
        $field = $this->client->call('crm.deal.fields')['result'][self::OWNER_SOURCE_FIELD] ?? null;
        $labels = [];
        foreach (\is_array($field) && \is_array($field['items'] ?? null) ? $field['items'] : [] as $item) {
            if (\is_array($item) && \is_scalar($item['ID'] ?? null) && \is_string($item['VALUE'] ?? null)) {
                $labels[(string) $item['ID']] = $item['VALUE'];
            }
        }

        return $labels;
    }

    public static function rawTable(): string
    {
        return 'raw_bitrix_deal';
    }
}
