<?php

declare(strict_types=1);

namespace App\Security\Access;

use App\Entity\Auth\User;
use App\Enum\OrgRole;
use App\Repository\Org\OrgMembershipRepository;
use App\Repository\Org\OrgUnitPathRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Single source of truth for org visibility. Voters, handlers and repositories must go
 * through it instead of reading roles or memberships themselves.
 *
 *  - ROLE_ADMIN or an org "admin" membership: every unit;
 *  - head: the unit and all its descendants (closure table);
 *  - member / viewer: only the unit itself;
 *  - anything else: nothing (fail closed).
 */
final class AccessScopeResolver implements ResetInterface
{
    /** @var array<int, AccessScope> */
    private array $cache = [];

    public function __construct(
        private readonly OrgMembershipRepository $memberships,
        private readonly OrgUnitPathRepository $paths,
        private readonly Connection $connection,
        private readonly Security $security,
    ) {
    }

    public function current(): AccessScope
    {
        return $this->resolve($this->security->getUser());
    }

    public function resolve(?UserInterface $user): AccessScope
    {
        if (!$user instanceof User || null === $user->getId() || !$user->isActive()) {
            return AccessScope::denied();
        }

        $userId = $user->getId();
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        $memberships = $this->memberships->findForUser($user);

        $isAdmin = $user->isAdmin();
        $branchRoots = [];
        $singleUnits = [];
        $memberUnits = [];
        foreach ($memberships as $membership) {
            $unitId = $membership->getOrgUnit()->getId();
            if (null === $unitId) {
                continue;
            }

            match ($membership->getRole()) {
                OrgRole::Admin => $isAdmin = true,
                OrgRole::Head => $branchRoots[] = $unitId,
                OrgRole::Member => $singleUnits[] = $memberUnits[] = $unitId,
                OrgRole::Viewer => $singleUnits[] = $unitId,
            };
        }

        if ($isAdmin) {
            return $this->cache[$userId] = AccessScope::everything($userId, $this->treeRootIds());
        }

        $branch = $this->paths->findBranchIds($branchRoots);
        $visible = array_values(array_unique([...$branch, ...$singleUnits]));
        if ([] === $visible) {
            return $this->cache[$userId] = AccessScope::denied($userId);
        }

        // Heads run their whole branch (facts + plans); members fill in facts of their own unit.
        return $this->cache[$userId] = AccessScope::units(
            $userId,
            $visible,
            $this->topMost($visible),
            [...$branch, ...$memberUnits],
            $branch,
        );
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * @return list<int>
     */
    private function treeRootIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM org_unit WHERE parent_id IS NULL ORDER BY sort, id');

        return array_map(intval(...), $ids);
    }

    /**
     * Visible units whose ancestors are all invisible: the entry points of the viewer's cabinet.
     *
     * @param list<int> $visible
     *
     * @return list<int>
     */
    private function topMost(array $visible): array
    {
        $roots = [];
        foreach ($visible as $unitId) {
            if ([] === array_intersect($this->paths->findAncestorIds($unitId), $visible)) {
                $roots[] = $unitId;
            }
        }
        sort($roots);

        return $roots;
    }
}
