<?php

declare(strict_types=1);

namespace App\Integration\Raw;

use Doctrine\DBAL\Connection;

/**
 * Batched INSERT ... ON DUPLICATE KEY UPDATE into raw_* snapshot tables.
 * Re-running a sync never duplicates rows; the newest payload wins.
 */
final readonly class RawUpserter
{
    private const int BATCH_SIZE = 200;

    /** Snapshot tables and their writable columns; the whitelist keeps SQL identifiers static. */
    private const array TABLES = [
        'raw_platform_booking' => ['id', 'status', 'portal', 'total', 'currency', 'arrival_date', 'departure_date', 'creation_date', 'last_modification', 'accommodation_id', 'payload', 'synced_at', 'rental_price', 'portal_commission', 'detail_modification'],
        'raw_platform_accommodation' => ['id', 'name', 'status', 'type', 'city', 'sales_activation_date', 'sales_deactivation_date', 'payload', 'synced_at'],
        'raw_platform_housekeeping_task' => ['id', 'status', 'type', 'accommodation_id', 'booking_id', 'assignee_id', 'due_date', 'creation_date', 'payload', 'synced_at'],
        'raw_platform_ticket' => ['id', 'title', 'status', 'type', 'priority', 'accommodation_id', 'price', 'creation_date', 'updated_at', 'payload', 'synced_at'],
        'raw_bitrix_deal' => ['id', 'category_id', 'stage_id', 'stage_semantic', 'source_id', 'owner_source', 'utm_source', 'assigned_by_id', 'lead_id', 'closed', 'date_create', 'date_modify', 'payload', 'synced_at'],
        'raw_bitrix_deal_stage' => ['id', 'category_id', 'name', 'sort', 'semantics', 'payload', 'synced_at'],
        'raw_bitrix_deal_stage_history' => ['id', 'deal_id', 'category_id', 'stage_id', 'stage_semantic', 'type_id', 'created_time', 'payload', 'synced_at'],
        'raw_bitrix_call' => ['id', 'direction', 'missed', 'completed', 'start_time', 'end_time', 'owner_type_id', 'owner_id', 'responsible_id', 'last_updated', 'payload', 'synced_at'],
    ];

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param iterable<array<string, scalar|null>> $rows rows keyed by column (payload already JSON-encoded)
     *
     * @return int number of rows written
     */
    public function upsert(string $table, iterable $rows): int
    {
        $columns = self::TABLES[$table] ?? throw new \InvalidArgumentException(\sprintf('Unknown raw table "%s".', $table));

        $count = 0;
        $batch = [];
        foreach ($rows as $row) {
            $batch[] = $row;
            if (\count($batch) >= self::BATCH_SIZE) {
                $count += $this->flush($table, $columns, $batch);
                $batch = [];
            }
        }

        return $count + $this->flush($table, $columns, $batch);
    }

    /**
     * @param list<string>                     $columns
     * @param list<array<string, scalar|null>> $batch
     */
    private function flush(string $table, array $columns, array $batch): int
    {
        if ([] === $batch) {
            return 0;
        }

        $placeholders = '('.implode(', ', array_fill(0, \count($columns), '?')).')';
        $updates = implode(', ', array_map(
            static fn (string $c): string => \sprintf('%1$s = VALUES(%1$s)', $c),
            array_values(array_diff($columns, ['id'])),
        ));

        $params = [];
        foreach ($batch as $row) {
            foreach ($columns as $column) {
                if (!\array_key_exists($column, $row)) {
                    throw new \InvalidArgumentException(\sprintf('Row for %s misses column "%s".', $table, $column));
                }
                $params[] = $row[$column];
            }
        }

        $this->connection->executeStatement(
            \sprintf(
                'INSERT INTO %s (%s) VALUES %s ON DUPLICATE KEY UPDATE %s',
                $table,
                implode(', ', $columns),
                implode(', ', array_fill(0, \count($batch), $placeholders)),
                $updates,
            ),
            $params,
        );

        return \count($batch);
    }
}
