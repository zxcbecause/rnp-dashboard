<?php

declare(strict_types=1);

namespace App\Service\Metric\Values;

use App\Entity\Metric\Metric;
use App\Entity\Metric\MetricLink;
use App\Entity\Metric\MetricPlan;
use App\Entity\Metric\MetricThreshold;
use App\Entity\Metric\MetricValue;
use App\Service\Metric\Formula\FormulaEvaluator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Loads everything needed to compute a set of metrics over a date range in a handful of queries:
 * the metrics, their formula and plan-formula components and roll-up children (transitively), values, plans
 * and thresholds.
 */
final readonly class MetricDataLoader
{
    public function __construct(
        private EntityManagerInterface $em,
        private FormulaEvaluator $formulas,
    ) {
    }

    /**
     * @param list<Metric> $metrics
     */
    public function load(array $metrics, \DateTimeImmutable $from, \DateTimeImmutable $to): MetricDataSet
    {
        $byId = [];
        foreach ($metrics as $metric) {
            $byId[(int) $metric->getId()] = $metric;
        }

        /** @var array<int, list<int>> $children parent id => child ids */
        $children = [];
        $pending = array_values($byId);

        while ([] !== $pending) {
            $codes = [];
            foreach ($pending as $metric) {
                if (null !== $metric->getFormula()) {
                    array_push($codes, ...$this->formulas->references($metric->getFormula()));
                }
                if (null !== $metric->getPlanFormula()) {
                    array_push($codes, ...$this->formulas->references($metric->getPlanFormula()));
                }
            }

            $new = [];
            if ([] !== $codes) {
                foreach ($this->em->getRepository(Metric::class)->findBy(['code' => array_values(array_unique($codes))]) as $component) {
                    $new[(int) $component->getId()] = $component;
                }
            }

            /** @var list<MetricLink> $links */
            $links = $this->em->getRepository(MetricLink::class)->findBy(['parentMetric' => $pending]);
            foreach ($links as $link) {
                $child = $link->getChildMetric();
                $children[(int) $link->getParentMetric()->getId()][] = (int) $child->getId();
                $new[(int) $child->getId()] = $child;
            }

            $pending = array_values(array_filter($new, static fn (Metric $m): bool => !isset($byId[(int) $m->getId()])));
            foreach ($pending as $metric) {
                $byId[(int) $metric->getId()] = $metric;
            }
        }

        $ids = array_keys($byId);

        /** @var list<MetricValue> $values */
        $values = $this->em->createQueryBuilder()
            ->select('v')->from(MetricValue::class, 'v')
            ->where('IDENTITY(v.metric) IN (:ids)')->andWhere('v.periodStart BETWEEN :from AND :to')
            ->setParameter('ids', $ids)->setParameter('from', $from->format('Y-m-d'))->setParameter('to', $to->format('Y-m-d'))
            ->getQuery()->getResult();

        /** @var list<MetricPlan> $plans */
        $plans = $this->em->createQueryBuilder()
            ->select('p')->from(MetricPlan::class, 'p')
            ->where('IDENTITY(p.metric) IN (:ids)')->andWhere('p.periodStart BETWEEN :from AND :to')
            ->setParameter('ids', $ids)->setParameter('from', $from->format('Y-m-d'))->setParameter('to', $to->format('Y-m-d'))
            ->getQuery()->getResult();

        /** @var list<MetricThreshold> $thresholds */
        $thresholds = $this->em->getRepository(MetricThreshold::class)->findAll();

        return new MetricDataSet($byId, $children, $values, $plans, $thresholds);
    }
}
