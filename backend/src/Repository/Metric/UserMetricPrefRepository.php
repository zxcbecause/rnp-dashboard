<?php

declare(strict_types=1);

namespace App\Repository\Metric;

use App\Entity\Auth\User;
use App\Entity\Metric\Metric;
use App\Entity\Metric\UserMetricPref;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserMetricPref>
 */
class UserMetricPrefRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserMetricPref::class);
    }

    /**
     * @param list<Metric> $metrics
     *
     * @return array<int, UserMetricPref> metric id => preference
     */
    public function findIndexed(User $user, array $metrics): array
    {
        if ([] === $metrics) {
            return [];
        }

        /** @var list<UserMetricPref> $prefs */
        $prefs = $this->findBy(['user' => $user, 'metric' => $metrics]);

        $indexed = [];
        foreach ($prefs as $pref) {
            $indexed[(int) $pref->getMetric()->getId()] = $pref;
        }

        return $indexed;
    }
}
