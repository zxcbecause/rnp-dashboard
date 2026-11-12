<?php

declare(strict_types=1);

namespace App\Integration\Sync;

use App\Entity\Sync\SyncRun;
use App\Enum\SyncSource;
use App\Repository\Sync\SyncRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Runs the syncers of one source, one sync_run row per resource. A failing resource is recorded
 * and does not stop the others; concurrent runs of the same source are prevented by a lock.
 */
final readonly class ResourceSyncRunner
{
    private const int ERROR_MAX_LENGTH = 2000;

    public function __construct(
        private SyncRunRepository $runs,
        private EntityManagerInterface $em,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, ResourceSyncer> $syncers   resource => syncer, in run order
     * @param list<string>                  $resources empty = all
     *
     * @return list<SyncRun>
     */
    public function run(SyncSource $source, array $syncers, array $resources = [], bool $full = false): array
    {
        $known = array_keys($syncers);
        $unknown = array_diff($resources, $known);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('Unknown %s resource(s): %s. Known: %s.', $source->value, implode(', ', $unknown), implode(', ', $known)));
        }

        $lock = $this->locks->createLock($source->value.'-sync', 3600);
        if (!$lock->acquire()) {
            throw new SyncAlreadyRunningException(\sprintf('Another %s sync is running.', $source->value));
        }

        try {
            $result = [];
            // Keep the declared order even for an explicit subset: later resources may rely on earlier ones.
            foreach ($known as $resource) {
                if ([] === $resources || \in_array($resource, $resources, true)) {
                    $result[] = $this->syncOne($source, $resource, $syncers[$resource], $full);
                }
            }

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function syncOne(SyncSource $source, string $resource, ResourceSyncer $syncer, bool $full): SyncRun
    {
        $since = $full ? null : $this->runs->findLastSuccessStart($source, $resource);

        $run = new SyncRun($source, $resource);
        $this->em->persist($run);
        $this->em->flush();

        try {
            $rows = $syncer->sync($since);
            if (null === $since) {
                $this->pruneVanished($syncer::rawTable(), $run->getStartedAt(), $rows, $resource);
            }
            $run->succeed($rows);
        } catch (\Throwable $e) {
            $this->logger->error('{source} sync of {resource} failed: {message}', ['source' => $source->value, 'resource' => $resource, 'message' => $e->getMessage()]);
            $run->fail(mb_substr($e->getMessage(), 0, self::ERROR_MAX_LENGTH));
        }

        $this->em->flush();

        return $run;
    }

    /**
     * After a successful full pass every row still in the source has synced_at ≥ the run start; older rows
     * were deleted there (or left the synced filter, e.g. a deal moved out of the owner pipelines).
     * A pass that returned less than half of the stored rows is not trusted to delete anything.
     */
    private function pruneVanished(string $table, \DateTimeImmutable $startedAt, int $rows, string $resource): void
    {
        if (1 !== preg_match('/^raw_[a-z_]+$/', $table)) {
            throw new \LogicException(\sprintf('Unexpected raw table name "%s".', $table));
        }

        $connection = $this->em->getConnection();
        $stored = (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table));
        if ($rows * 2 < $stored) {
            $this->logger->warning('{resource}: full sync returned {rows} of {stored} stored rows; vanished rows are kept.', ['resource' => $resource, 'rows' => $rows, 'stored' => $stored]);

            return;
        }

        $deleted = $connection->executeStatement(\sprintf('DELETE FROM %s WHERE synced_at < ?', $table), [$startedAt->format('Y-m-d H:i:s')]);
        if ($deleted > 0) {
            $this->logger->info('{resource}: {deleted} rows vanished from the source and were removed.', ['resource' => $resource, 'deleted' => $deleted]);
        }
    }
}
