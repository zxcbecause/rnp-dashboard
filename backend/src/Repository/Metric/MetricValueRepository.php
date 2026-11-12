<?php

declare(strict_types=1);

namespace App\Repository\Metric;

use App\Entity\Metric\Metric;
use App\Entity\Metric\MetricValue;
use App\Enum\PeriodType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MetricValue>
 */
class MetricValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MetricValue::class);
    }

    /**
     * Values of one metric whose period starts within [from, to], keyed by "Y-m-d" of the start.
     * The lower bound is widened to the month start, matching how periods are recalculated.
     *
     * @return array<string, MetricValue>
     */
    public function findIndexedByStart(Metric $metric, PeriodType $type, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<MetricValue> $values */
        $values = $this->createQueryBuilder('v')
            ->where('v.metric = :metric')
            ->andWhere('v.periodType = :type')
            ->andWhere('v.periodStart BETWEEN :from AND :to')
            ->setParameter('metric', $metric)
            ->setParameter('type', $type)
            ->setParameter('from', $from->modify('first day of this month')->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($values as $value) {
            $indexed[$value->getPeriodStart()->format('Y-m-d')] = $value;
        }

        return $indexed;
    }
}
