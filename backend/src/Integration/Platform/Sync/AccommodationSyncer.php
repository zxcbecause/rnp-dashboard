<?php

declare(strict_types=1);

namespace App\Integration\Platform\Sync;

use App\Integration\Platform\PlatformApiClient;
use App\Integration\Platform\PlatformApiException;
use App\Integration\Raw\RawUpserter;
use App\Integration\Raw\RawValue;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * GET /api/accommodations → raw_platform_accommodation. Always a full list sync (a few hundred rows).
 * The default list holds ACTIVE + INACTIVE; DELETED ones only come with ?status=DELETED.
 *
 * The list has no sales activation/deactivation dates: only GET /api/accommodations/{id} has them.
 * A full run ($since = null, the nightly --full) fetches every detail card; incremental runs keep
 * the dates already stored, so they stay cheap and never wipe the dates out.
 */
final readonly class AccommodationSyncer implements PlatformResourceSyncer
{
    public function __construct(
        private PlatformApiClient $client,
        private RawUpserter $upserter,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public static function resource(): string
    {
        return 'accommodations';
    }

    public function sync(?\DateTimeImmutable $since): int
    {
        return $this->upserter->upsert(self::rawTable(), $this->rows([
            $this->client->paginate('/api/accommodations'),
            $this->client->paginate('/api/accommodations', ['status' => 'DELETED']),
        ], null === $since));
    }

    /**
     * @param list<iterable<array<string, mixed>>> $sources
     *
     * @return \Generator<array<string, scalar|null>>
     */
    private function rows(array $sources, bool $withDetails): \Generator
    {
        $now = RawValue::now();
        $stored = $this->storedSalesDates();

        foreach ($sources as $items) {
            foreach ($items as $item) {
                $id = RawValue::int($item, 'id');
                if (null === $id) {
                    continue;
                }

                $detail = $withDetails ? $this->detail($id) : null;
                if (null !== $detail) {
                    $item = [...$item, ...$detail];
                    $activation = RawValue::date($item, 'salesActivationDate');
                    $deactivation = RawValue::date($item, 'salesDeactivationDate');
                } else {
                    // Incremental run, or the card is missing (404): keep what we know.
                    [$activation, $deactivation] = $stored[$id] ?? [null, null];
                }

                yield [
                    'id' => $id,
                    'name' => RawValue::string($item, 'name'),
                    'status' => RawValue::string($item, 'status', 16),
                    'type' => RawValue::string($item, 'type', 32),
                    'city' => RawValue::string($item, 'city', 128),
                    'sales_activation_date' => $activation,
                    'sales_deactivation_date' => $deactivation,
                    'payload' => RawValue::json($item),
                    'synced_at' => $now,
                ];
            }
        }
    }

    /**
     * Detail card fields; null when the card is missing (404), which must not fail the whole sync.
     * Anything else (auth, 5xx, network) fails the resource as usual.
     *
     * @return array<string, mixed>|null
     */
    private function detail(int $id): ?array
    {
        try {
            return $this->client->get('/api/accommodations/'.$id);
        } catch (PlatformApiException $e) {
            if (404 !== $e->statusCode) {
                throw $e;
            }
            $this->logger->warning('Platform accommodation {id} detail skipped: {message}', ['id' => $id, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<int, array{?string, ?string}>
     */
    private function storedSalesDates(): array
    {
        $dates = [];
        foreach ($this->connection->iterateAssociative('SELECT id, sales_activation_date, sales_deactivation_date FROM raw_platform_accommodation') as $row) {
            $dates[(int) $row['id']] = [
                null === $row['sales_activation_date'] ? null : (string) $row['sales_activation_date'],
                null === $row['sales_deactivation_date'] ? null : (string) $row['sales_deactivation_date'],
            ];
        }

        return $dates;
    }

    public static function rawTable(): string
    {
        return 'raw_platform_accommodation';
    }
}
