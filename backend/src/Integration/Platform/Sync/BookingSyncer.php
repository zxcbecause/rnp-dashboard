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
 * GET /api/bookings → raw_platform_booking.
 * Incremental through the lastModification filter (>=), with an overlap against clock skew.
 *
 * rentalPrice and portalCommission exist only in GET /api/bookings/{id}. The card is fetched for guest
 * bookings whose lastModification differs from the one seen at the previous fetch, so a run only pays
 * for new and changed bookings (the first full run after the upgrade backfills the history).
 * Only those two numbers are kept from the card: it also holds guest names and comments.
 */
final readonly class BookingSyncer implements PlatformResourceSyncer
{
    private const string OVERLAP = '-1 hour';

    /** Owner stays and calendar blocks: no price, never part of money metrics. */
    private const array NO_DETAIL_STATUSES = ['OWNER', 'UNAVAILABLE'];
    /** That many unreadable cards in a row mean the platform is down, not one broken booking. */
    private const int MAX_DETAIL_FAILURES_IN_A_ROW = 10;

    public function __construct(
        private PlatformApiClient $client,
        private RawUpserter $upserter,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public static function resource(): string
    {
        return 'bookings';
    }

    public function sync(?\DateTimeImmutable $since): int
    {
        $query = null === $since ? [] : ['lastModification' => $since->modify(self::OVERLAP)->format(\DATE_ATOM)];

        $count = $this->upserter->upsert(self::rawTable(), $this->rows($this->client->paginate('/api/bookings', $query)));
        $this->markCancellations();

        return $count;
    }

    /**
     * @param iterable<array<string, mixed>> $items
     *
     * @return \Generator<array<string, scalar|null>>
     */
    private function rows(iterable $items): \Generator
    {
        $now = RawValue::now();
        $stored = $this->storedDetails();
        $failedInARow = 0;

        foreach ($items as $item) {
            $id = RawValue::int($item, 'id');
            if (null === $id) {
                continue;
            }

            $status = RawValue::string($item, 'status', 32);
            $modified = RawValue::datetime($item, 'lastModification');
            $detail = $stored[$id] ?? ['rental_price' => null, 'portal_commission' => null, 'detail_modification' => null];

            if (!\in_array($status, self::NO_DETAIL_STATUSES, true) && (null === $modified || $detail['detail_modification'] !== $modified)) {
                $card = $this->detail($id, $modified);
                $failedInARow = null === $card ? $failedInARow + 1 : 0;
                if ($failedInARow >= self::MAX_DETAIL_FAILURES_IN_A_ROW) {
                    // Not one broken card: the platform itself fails, the resource fails as before.
                    throw new PlatformApiException(\sprintf('%d booking cards in a row could not be read.', $failedInARow), null);
                }
                $detail = $card ?? $detail;
            }

            yield [
                'id' => $id,
                'status' => $status,
                'portal' => RawValue::string($item, 'portal', 128),
                'total' => RawValue::decimal($item, 'total'),
                'currency' => RawValue::string($item, 'currency', 8),
                'arrival_date' => RawValue::datetime($item, 'arrivalDate'),
                'departure_date' => RawValue::datetime($item, 'departureDate'),
                'creation_date' => RawValue::datetime($item, 'creationDate'),
                'last_modification' => $modified,
                'accommodation_id' => RawValue::int($item, 'accommodationId'),
                'payload' => RawValue::json($item),
                'synced_at' => $now,
                ...$detail,
            ];
        }
    }

    public static function rawTable(): string
    {
        return 'raw_platform_booking';
    }

    /**
     * Price fields of the detail card; null when the card cannot be read (404, or an error left after the
     * client's retries). One broken card must not stop the whole resource — the list rows, the cursor and the
     * cancellations still move on; the stored prices stay and the card is retried next time.
     *
     * @return array{rental_price: ?string, portal_commission: ?string, detail_modification: ?string}|null
     */
    private function detail(int $id, ?string $modified): ?array
    {
        try {
            $card = $this->client->get('/api/bookings/'.$id);
        } catch (PlatformApiException $e) {
            $this->logger->warning('Platform booking {id} detail skipped: {message}', ['id' => $id, 'message' => $e->getMessage()]);

            return null;
        }

        return [
            'rental_price' => RawValue::decimal($card, 'rentalPrice'),
            'portal_commission' => RawValue::decimal($card, 'portalCommission'),
            'detail_modification' => $modified,
        ];
    }

    /**
     * @return array<int, array{rental_price: ?string, portal_commission: ?string, detail_modification: ?string}>
     */
    private function storedDetails(): array
    {
        $details = [];
        $rows = $this->connection->iterateAssociative(
            'SELECT id, rental_price, portal_commission, detail_modification FROM raw_platform_booking WHERE detail_modification IS NOT NULL',
        );
        foreach ($rows as $row) {
            $details[(int) $row['id']] = [
                'rental_price' => null === $row['rental_price'] ? null : (string) $row['rental_price'],
                'portal_commission' => null === $row['portal_commission'] ? null : (string) $row['portal_commission'],
                'detail_modification' => (string) $row['detail_modification'],
            ];
        }

        return $details;
    }

    /**
     * Keeps cancelled_at (not part of the upsert): set once when a booking is first seen cancelled, cleared when it
     * is no longer cancelled.
     */
    private function markCancellations(): void
    {
        $this->connection->executeStatement(
            "UPDATE raw_platform_booking SET cancelled_at = COALESCE(last_modification, synced_at) WHERE status = 'CANCELLED' AND cancelled_at IS NULL",
        );
        $this->connection->executeStatement(
            "UPDATE raw_platform_booking SET cancelled_at = NULL WHERE (status IS NULL OR status <> 'CANCELLED') AND cancelled_at IS NOT NULL",
        );
    }
}
