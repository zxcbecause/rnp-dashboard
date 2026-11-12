<?php

declare(strict_types=1);

namespace App\Service\Metric;

use App\Entity\Auth\User;
use App\Entity\Metric\Metric;
use App\Repository\Metric\UserMetricPrefRepository;

/**
 * Applies a user's personal view (order and hidden metrics) to the metrics of one unit.
 * Metrics without a preference keep the default order (metric.sort) after the arranged ones,
 * so a metric added later is never lost from the view.
 */
final readonly class MetricViewPrefs
{
    public function __construct(private UserMetricPrefRepository $prefs)
    {
    }

    /**
     * @param list<Metric> $metrics in default order
     *
     * @return list<array{metric: Metric, hidden: bool}>
     */
    public function arrange(User $user, array $metrics): array
    {
        $prefs = $this->prefs->findIndexed($user, $metrics);

        $items = [];
        foreach ($metrics as $position => $metric) {
            $pref = $prefs[(int) $metric->getId()] ?? null;
            $items[] = [
                'metric' => $metric,
                'hidden' => null !== $pref && !$pref->isVisible(),
                // Arranged metrics first (by their sort), then the rest in default order.
                'key' => null !== $pref ? [0, $pref->getSort(), $position] : [1, 0, $position],
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        return array_map(static fn (array $i): array => ['metric' => $i['metric'], 'hidden' => $i['hidden']], $items);
    }
}
