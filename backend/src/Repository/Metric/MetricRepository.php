<?php

declare(strict_types=1);

namespace App\Repository\Metric;

use App\Entity\Metric\Metric;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Metric>
 */
class MetricRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Metric::class);
    }

    /**
     * Active metrics of the given org units, in display order.
     *
     * @param list<int> $orgUnitIds
     *
     * @return list<Metric>
     */
    public function findActiveByOrgUnits(array $orgUnitIds): array
    {
        if ([] === $orgUnitIds) {
            return [];
        }

        /** @var list<Metric> $result */
        $result = $this->createQueryBuilder('m')
            ->where('IDENTITY(m.orgUnit) IN (:units)')
            ->andWhere('m.isActive = true')
            ->setParameter('units', $orgUnitIds)
            ->orderBy('m.sort', \SortDirection::Ascending)
            ->addOrderBy('m.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
