<?php

declare(strict_types=1);

namespace App\Repository\Org;

use App\Entity\Org\OrgUnit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrgUnit>
 */
class OrgUnitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrgUnit::class);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<OrgUnit>
     */
    public function findByIdsOrdered(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<OrgUnit> $result */
        $result = $this->createQueryBuilder('u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('u.sort', \SortDirection::Ascending)
            ->addOrderBy('u.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
