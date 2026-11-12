<?php

declare(strict_types=1);

namespace App\Integration\Bitrix\Sync;

use App\Entity\Sync\SyncRun;
use App\Enum\SyncSource;
use App\Integration\Sync\ResourceSyncRunner;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs the Bitrix24 syncers (see ResourceSyncRunner) in priority order:
 * deal stages, deals, stage history, calls.
 */
final readonly class BitrixSyncHandler
{
    /** @var array<string, BitrixResourceSyncer> */
    private array $syncers;

    /**
     * @param iterable<string, BitrixResourceSyncer> $syncers
     */
    public function __construct(
        #[AutowireIterator(BitrixResourceSyncer::TAG, defaultIndexMethod: 'resource')]
        iterable $syncers,
        private ResourceSyncRunner $runner,
    ) {
        $this->syncers = iterator_to_array($syncers);
    }

    /**
     * @return list<string>
     */
    public function resources(): array
    {
        return array_keys($this->syncers);
    }

    /**
     * @param list<string> $resources empty = all
     *
     * @return list<SyncRun>
     */
    public function handle(array $resources = [], bool $full = false): array
    {
        return $this->runner->run(SyncSource::Bitrix, $this->syncers, $resources, $full);
    }
}
