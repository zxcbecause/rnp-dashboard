<?php

declare(strict_types=1);

namespace App\Repository\Org;

use App\Entity\Auth\User;
use App\Entity\Org\OrgMembership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrgMembership>
 */
class OrgMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrgMembership::class);
    }

    /**
     * @return list<OrgMembership>
     */
    public function findForUser(User $user): array
    {
        /** @var list<OrgMembership> $result */
        $result = $this->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.orgUnit', 'u')
            ->where('m.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Active users with a membership in any of the given units, ordered by name.
     *
     * @param list<int> $unitIds
     *
     * @return list<User>
     */
    public function findActiveUsersInUnits(array $unitIds): array
    {
        if ([] === $unitIds) {
            return [];
        }

        /** @var list<User> $users */
        $users = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT u')
            ->from(User::class, 'u')
            ->join(OrgMembership::class, 'm', 'WITH', 'm.user = u')
            ->where('IDENTITY(m.orgUnit) IN (:units)')
            ->andWhere('u.isActive = true')
            ->setParameter('units', $unitIds)
            ->orderBy('u.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $users;
    }
}
