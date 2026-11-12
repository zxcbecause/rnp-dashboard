<?php

declare(strict_types=1);

namespace App\Service\Metric;

use App\DTO\Request\MetricViewRequest;
use App\Entity\Auth\User;
use App\Entity\Metric\UserMetricPref;
use App\Repository\Metric\MetricRepository;
use App\Repository\Metric\UserMetricPrefRepository;
use App\Service\Org\OrgUnitFindHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Saves / resets the personal order and visibility of a unit's metrics. Only the view of the
 * current user changes; anyone who can see the unit may arrange it (viewers included).
 */
final readonly class MetricViewHandler
{
    public function __construct(
        private OrgUnitFindHandler $units,
        private MetricRepository $metrics,
        private UserMetricPrefRepository $prefs,
        private EntityManagerInterface $em,
    ) {
    }

    public function save(int $orgUnitId, MetricViewRequest $request, User $user): void
    {
        $unit = $this->units->handle($orgUnitId);
        $metrics = [];
        foreach ($this->metrics->findActiveByOrgUnits([(int) $unit->getId()]) as $metric) {
            $metrics[(int) $metric->getId()] = $metric;
        }

        $ids = array_map(static fn ($item): int => $item->metricId, $request->items);
        if (\count($ids) !== \count(array_unique($ids))) {
            throw new UnprocessableEntityHttpException('Метрика указана дважды.');
        }
        $expected = array_keys($metrics);
        sort($ids);
        sort($expected);
        if ($ids !== $expected) {
            throw new UnprocessableEntityHttpException('Нужно передать все активные метрики отдела, и только их.');
        }
        if ([] === array_filter($request->items, static fn ($item): bool => $item->visible)) {
            throw new UnprocessableEntityHttpException('Нельзя скрыть все метрики отдела.');
        }

        $existing = $this->prefs->findIndexed($user, array_values($metrics));
        foreach ($request->items as $position => $item) {
            $pref = $existing[$item->metricId] ?? null;
            if (null === $pref) {
                $pref = new UserMetricPref($user, $metrics[$item->metricId]);
                $this->em->persist($pref);
            }
            $pref->setVisible($item->visible)->setSort(($position + 1) * 10);
        }

        $this->em->flush();
    }

    public function reset(int $orgUnitId, User $user): void
    {
        $unit = $this->units->handle($orgUnitId);

        foreach ($this->prefs->findIndexed($user, $this->metrics->findActiveByOrgUnits([(int) $unit->getId()])) as $pref) {
            $this->em->remove($pref);
        }

        $this->em->flush();
    }
}
