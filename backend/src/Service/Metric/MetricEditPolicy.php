<?php

declare(strict_types=1);

namespace App\Service\Metric;

use App\Entity\Auth\User;
use App\Entity\Metric\Metric;
use App\Security\Access\AccessScope;

/**
 * Who may change what:
 *  - facts: only manual metrics; heads of the branch, members of the unit, the metric's filler, admins;
 *  - plans: heads of the branch and admins (any metric, including ratios and auto metrics; for a metric with a
 *    plan formula the set plan overrides the formula for that period).
 * Visibility is checked separately (missing/foreign metrics answer 404).
 */
final class MetricEditPolicy
{
    public static function canEnterFact(Metric $metric, AccessScope $scope, ?User $user): bool
    {
        if (!$metric->isManual() || null !== $metric->getFormula()) {
            return false;
        }

        $unitId = $metric->getOrgUnit()->getId();

        return $scope->canEnterFacts($unitId)
            || (null !== $user && $metric->getFillerUser()?->getId() === $user->getId() && $scope->canSeeUnit($unitId));
    }

    public static function canSetPlan(Metric $metric, AccessScope $scope): bool
    {
        return $scope->canSetPlans($metric->getOrgUnit()->getId());
    }

    /**
     * Explaining a deviation (reason + action) is open to whoever answers for the metric's numbers — heads, members
     * of the unit and the metric's filler — for automatic metrics too.
     */
    public static function canExplainDeviation(Metric $metric, AccessScope $scope, ?User $user): bool
    {
        $unitId = $metric->getOrgUnit()->getId();

        return $scope->canEnterFacts($unitId)
            || (null !== $user && $metric->getFillerUser()?->getId() === $user->getId() && $scope->canSeeUnit($unitId));
    }
}
