<?php

declare(strict_types=1);

namespace App\Service\Action;

use App\Entity\Action\BaseFunction;
use App\Entity\Action\RoadmapItem;
use App\Entity\Auth\User;
use App\Security\Access\AccessScope;

/**
 * Who may change actions of an org unit (visibility is checked separately, foreign → 404):
 *  - manage (create / edit base functions and roadmap items, assess effect): heads of the branch, admins;
 *  - check a base function: heads, members of the unit, the function owner;
 *  - change a roadmap item status: managers and the item owner.
 */
final class ActionPolicy
{
    public static function canManage(?int $orgUnitId, AccessScope $scope): bool
    {
        return $scope->canSetPlans($orgUnitId);
    }

    public static function canCheck(BaseFunction $function, AccessScope $scope, User $user): bool
    {
        return $scope->canEnterFacts($function->getOrgUnit()->getId())
            || ($function->getOwnerUser()?->getId() === $user->getId() && $scope->canSeeUnit($function->getOrgUnit()->getId()));
    }

    public static function canChangeStatus(RoadmapItem $item, AccessScope $scope, User $user): bool
    {
        return self::canManage($item->getMetric()->getOrgUnit()->getId(), $scope)
            || ($item->getOwner()?->getId() === $user->getId() && $scope->canSeeUnit($item->getMetric()->getOrgUnit()->getId()));
    }
}
