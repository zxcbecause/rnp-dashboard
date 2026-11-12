<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\DTO\Sync\DataStatusDto;
use App\DTO\Sync\ResourceStatusDto;
use App\DTO\Sync\SourceStatusDto;
use App\Enum\SyncSource;
use App\Enum\SyncStatus;
use App\Integration\Bitrix\BitrixClient;
use App\Integration\Bitrix\Sync\BitrixSyncHandler;
use App\Integration\Platform\PlatformApiClient;
use App\Integration\Platform\Sync\PlatformSyncHandler;
use Doctrine\DBAL\Connection;

/**
 * Freshness of the scheduled sources, from sync_run. A source is
 *  - stale when one of its resources has not succeeded for STALE_AFTER (the schedule runs every 30 minutes);
 *  - failing when the last run of one of its resources failed.
 * Push API and imports are on demand, so they are not reported.
 */
final readonly class DataStatusHandler
{
    public const string STALE_AFTER = '-2 hours';

    public function __construct(
        private Connection $connection,
        private PlatformApiClient $platform,
        private PlatformSyncHandler $platformSync,
        private BitrixClient $bitrix,
        private BitrixSyncHandler $bitrixSync,
    ) {
    }

    public function handle(bool $withErrors, ?\DateTimeImmutable $now = null): DataStatusDto
    {
        $now ??= new \DateTimeImmutable();

        return new DataStatusDto($now->format(\DATE_ATOM), [
            $this->source(SyncSource::PlatformApi, $this->platform->isConfigured(), $this->platformSync->resources(), $withErrors, $now),
            $this->source(SyncSource::Bitrix, $this->bitrix->isConfigured(), $this->bitrixSync->resources(), $withErrors, $now),
        ]);
    }

    /**
     * @param list<string> $resources
     */
    private function source(SyncSource $source, bool $configured, array $resources, bool $withErrors, \DateTimeImmutable $now): SourceStatusDto
    {
        $runs = $this->lastRuns($source);
        $statuses = [];
        $successes = [];
        $failing = false;

        foreach ($resources as $resource) {
            $run = $runs[$resource] ?? null;
            $lastSuccess = $run['last_success'] ?? null;
            $successes[] = $lastSuccess;
            $failing = $failing || SyncStatus::Failed->value === ($run['status'] ?? null);

            $statuses[] = new ResourceStatusDto(
                $resource,
                $this->iso($run['started_at'] ?? null),
                $run['status'] ?? null,
                $this->iso($lastSuccess),
                $withErrors && SyncStatus::Failed->value === ($run['status'] ?? null) ? ($run['error'] ?? null) : null,
            );
        }

        $dataAsOf = $configured && [] !== $successes && !\in_array(null, $successes, true) ? min($successes) : null;
        $stale = $configured && (null === $dataAsOf || new \DateTimeImmutable($dataAsOf) < $now->modify(self::STALE_AFTER));

        return new SourceStatusDto($source->value, $configured, $this->iso($dataAsOf), $stale, $configured && $failing, $statuses);
    }

    /**
     * @return array<string, array{started_at: string, status: string, error: ?string, last_success: ?string}>
     */
    private function lastRuns(SyncSource $source): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.scope, r.started_at, r.status, r.error,
                    (SELECT MAX(s.started_at) FROM sync_run s WHERE s.source = r.source AND s.scope = r.scope AND s.status = :success) AS last_success
             FROM sync_run r
             WHERE r.source = :source
               AND r.id = (SELECT MAX(l.id) FROM sync_run l WHERE l.source = r.source AND l.scope = r.scope)',
            ['source' => $source->value, 'success' => SyncStatus::Success->value],
        );

        $runs = [];
        foreach ($rows as $row) {
            $runs[(string) $row['scope']] = [
                'started_at' => (string) $row['started_at'],
                'status' => (string) $row['status'],
                'error' => null === $row['error'] ? null : (string) $row['error'],
                'last_success' => null === $row['last_success'] ? null : (string) $row['last_success'],
            ];
        }

        return $runs;
    }

    /**
     * sync_run times are app-timezone (Madrid) wall time; the API answers ISO 8601 with offset.
     */
    private function iso(?string $dbTime): ?string
    {
        return null === $dbTime ? null : new \DateTimeImmutable($dbTime)->format(\DATE_ATOM);
    }
}
