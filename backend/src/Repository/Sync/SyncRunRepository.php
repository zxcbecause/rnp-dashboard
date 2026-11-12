<?php

declare(strict_types=1);

namespace App\Repository\Sync;

use App\Entity\Sync\SyncRun;
use App\Enum\SyncSource;
use App\Enum\SyncStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SyncRun>
 */
class SyncRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncRun::class);
    }

    /**
     * Start time of the last successful run: the next incremental run asks for changes since then.
     */
    public function findLastSuccessStart(SyncSource $source, string $scope): ?\DateTimeImmutable
    {
        /** @var SyncRun|null $run */
        $run = $this->createQueryBuilder('r')
            ->where('r.source = :source')
            ->andWhere('r.scope = :scope')
            ->andWhere('r.status = :status')
            ->setParameter('source', $source)
            ->setParameter('scope', $scope)
            ->setParameter('status', SyncStatus::Success)
            ->orderBy('r.startedAt', \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $run?->getStartedAt();
    }
}
