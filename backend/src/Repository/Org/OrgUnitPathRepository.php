<?php

declare(strict_types=1);

namespace App\Repository\Org;

use App\Entity\Org\OrgUnitPath;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrgUnitPath>
 */
class OrgUnitPathRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrgUnitPath::class);
    }

    /**
     * Ids of the given units and all their descendants.
     *
     * @param list<int> $ancestorIds
     *
     * @return list<int>
     */
    public function findBranchIds(array $ancestorIds): array
    {
        if ([] === $ancestorIds) {
            return [];
        }

        /** @var list<int|string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT descendant_id FROM org_unit_path WHERE ancestor_id IN (?) ORDER BY descendant_id',
            [$ancestorIds],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        return array_map(intval(...), $ids);
    }

    /**
     * Ancestor ids of a unit from the root down to its parent (self excluded).
     *
     * @return list<int>
     */
    public function findAncestorIds(int $unitId): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT ancestor_id FROM org_unit_path WHERE descendant_id = ? AND depth > 0 ORDER BY depth DESC',
            [$unitId],
        );

        return array_map(intval(...), $ids);
    }
}
