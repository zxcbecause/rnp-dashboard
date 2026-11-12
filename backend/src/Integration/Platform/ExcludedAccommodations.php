<?php

declare(strict_types=1);

namespace App\Integration\Platform;

use Doctrine\DBAL\Connection;

/**
 * The list of platform accommodations no metric counts (test listings), see Entity\Raw\ExcludedAccommodation.
 * Changing the list does not touch stored facts: recalculate the metrics afterwards.
 */
final readonly class ExcludedAccommodations
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return bool false when the accommodation was excluded already (its note is updated)
     */
    public function add(int $accommodationId, ?string $note): bool
    {
        if (false === $this->connection->fetchOne('SELECT 1 FROM raw_platform_accommodation WHERE id = ?', [$accommodationId])) {
            throw new \InvalidArgumentException(\sprintf('Accommodation %d is not among the synced platform accommodations.', $accommodationId));
        }

        $note = null === $note || '' === trim($note) ? null : mb_substr(trim($note), 0, 255);
        if (false !== $this->connection->fetchOne('SELECT 1 FROM excluded_accommodation WHERE accommodation_id = ?', [$accommodationId])) {
            $this->connection->update('excluded_accommodation', ['note' => $note], ['accommodation_id' => $accommodationId]);

            return false;
        }

        $this->connection->insert('excluded_accommodation', [
            'accommodation_id' => $accommodationId,
            'note' => $note,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return true;
    }

    public function remove(int $accommodationId): bool
    {
        return $this->connection->delete('excluded_accommodation', ['accommodation_id' => $accommodationId]) > 0;
    }

    /**
     * @return list<array{id: int, name: string|null, status: string|null, note: string|null, createdAt: string}>
     */
    public function list(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT x.accommodation_id, a.name, a.status, x.note, x.created_at
             FROM excluded_accommodation x
             LEFT JOIN raw_platform_accommodation a ON a.id = x.accommodation_id
             ORDER BY x.accommodation_id',
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['accommodation_id'],
            'name' => null === $row['name'] ? null : (string) $row['name'],
            'status' => null === $row['status'] ? null : (string) $row['status'],
            'note' => null === $row['note'] ? null : (string) $row['note'],
            'createdAt' => (string) $row['created_at'],
        ], $rows);
    }
}
