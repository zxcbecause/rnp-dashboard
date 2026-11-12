<?php

declare(strict_types=1);

namespace App\Integration\Platform\Sync;

use App\Entity\Sync\SyncRun;
use App\Enum\SyncSource;
use App\Integration\Sync\ResourceSyncRunner;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs the platform syncers (see ResourceSyncRunner): one sync_run row per resource,
 * a failing resource does not stop the others, concurrent runs are locked out.
 */
final readonly class PlatformSyncHandler
{
    /** @var array<string, PlatformResourceSyncer> */
    private array $syncers;

    /**
     * @param iterable<string, PlatformResourceSyncer> $syncers
     */
    public function __construct(
        #[AutowireIterator(PlatformResourceSyncer::TAG, defaultIndexMethod: 'resource')]
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
        return $this->runner->run(SyncSource::PlatformApi, $this->syncers, $resources, $full);
    }
}
