<?php

declare(strict_types=1);

namespace App\Service\Org;

use App\Entity\Auth\User;
use App\Entity\Org\OrgMembership;
use App\Entity\Org\OrgUnit;
use App\Enum\OrgRole;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Org structure changes shared by the console (app:org) and the admin screen: units, heads, memberships.
 * Messages of \InvalidArgumentException are user-facing.
 *
 * A head is two things kept together: org_unit.head_user_id (who it is) and a "head" membership (what it
 * opens: the whole branch). Replacing or removing the head turns the previous head into a viewer of the unit.
 */
final readonly class OrgStructureManager
{
    public const int MAX_NAME_LENGTH = 255;

    public function __construct(
        private EntityManagerInterface $em,
        private OrgTreeManager $tree,
    ) {
    }

    /**
     * @throws \InvalidArgumentException on an empty or taken name
     */
    public function createUnit(string $name, OrgUnit $parent, int $sort = 0): OrgUnit
    {
        $name = $this->validName($name);
        $this->assertNameFree($parent, $name, null);

        return $this->tree->createUnit($name, $parent, $sort);
    }

    /**
     * @throws \InvalidArgumentException on an empty or taken name
     */
    public function rename(OrgUnit $unit, string $name): void
    {
        $name = $this->validName($name);
        if (null !== $unit->getParent()) {
            $this->assertNameFree($unit->getParent(), $name, $unit);
        }
        $unit->setName($name);
        $this->em->flush();
    }

    /**
     * @throws \InvalidArgumentException on a taken name under the new parent, a move into the own branch or of the root
     */
    public function move(OrgUnit $unit, OrgUnit $parent): void
    {
        if ($unit->getParent() === $parent) {
            return;
        }
        if (null !== $unit->getParent()) {
            $this->assertNameFree($parent, $unit->getName(), $unit);
        }
        try {
            $this->tree->moveUnit($unit, $parent);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(null === $unit->getParent() ? 'Корневой отдел нельзя перенести.' : 'Нельзя перенести отдел внутрь его же ветки.', 0, $e);
        }
    }

    /**
     * Rename and move in one change: the new name must be free under the target parent (the current one when
     * $parent is null or the same).
     *
     * @throws \InvalidArgumentException on an empty or taken name, a move into the own branch or of the root
     */
    public function update(OrgUnit $unit, string $name, ?OrgUnit $parent): void
    {
        $name = $this->validName($name);
        $target = $parent ?? $unit->getParent();
        if (null !== $target) {
            $this->assertNameFree($target, $name, $unit);
        }
        if (null !== $parent && $parent !== $unit->getParent()) {
            try {
                $this->tree->moveUnit($unit, $parent);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(null === $unit->getParent() ? 'Корневой отдел нельзя перенести.' : 'Нельзя перенести отдел внутрь его же ветки.', 0, $e);
            }
        }
        $unit->setName($name);
        $this->em->flush();
    }

    /**
     * Null removes the head. The previous head loses the branch but stays in the unit as a viewer (the least
     * rights: sees the unit, enters nothing) — the administrator changes the role or removes them.
     */
    public function setHead(OrgUnit $unit, ?User $user): void
    {
        $previous = $unit->getHeadUser();
        if (null !== $previous && $previous !== $user) {
            $old = $this->membership($unit, $previous);
            if (null !== $old && OrgRole::Head === $old->getRole()) {
                $old->setRole(OrgRole::Viewer);
            }
        }

        $unit->setHeadUser($user);
        if (null !== $user) {
            $this->upsertMembership($unit, $user, OrgRole::Head);
        }
        $this->em->flush();
    }

    /**
     * A "head" role goes through setHead(), so the unit always shows who its head is.
     */
    public function setMember(OrgUnit $unit, User $user, OrgRole $role): void
    {
        if (OrgRole::Head === $role) {
            $this->setHead($unit, $user);

            return;
        }
        if ($unit->getHeadUser() === $user) {
            $unit->setHeadUser(null);
        }
        $this->upsertMembership($unit, $user, $role);
        $this->em->flush();
    }

    /**
     * @throws \InvalidArgumentException when the user is not in the unit
     */
    public function removeMember(OrgUnit $unit, User $user): void
    {
        $membership = $this->membership($unit, $user) ?? throw new \InvalidArgumentException(\sprintf('%s не состоит в отделе «%s».', $user->getEmail(), $unit->getName()));
        $this->em->remove($membership);
        if ($unit->getHeadUser() === $user) {
            $unit->setHeadUser(null);
        }
        $this->em->flush();
    }

    public function membership(OrgUnit $unit, User $user): ?OrgMembership
    {
        return $this->em->getRepository(OrgMembership::class)->findOneBy(['orgUnit' => $unit, 'user' => $user]);
    }

    private function upsertMembership(OrgUnit $unit, User $user, OrgRole $role): void
    {
        $membership = $this->membership($unit, $user);
        if (null === $membership) {
            $this->em->persist(new OrgMembership($user, $unit, $role));
        } else {
            $membership->setRole($role);
        }
    }

    private function validName(string $name): string
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \InvalidArgumentException('Название отдела не может быть пустым.');
        }
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('Название отдела — не длиннее %d символов.', self::MAX_NAME_LENGTH));
        }

        return $name;
    }

    private function assertNameFree(OrgUnit $parent, string $name, ?OrgUnit $except): void
    {
        foreach ($this->em->getRepository(OrgUnit::class)->findBy(['parent' => $parent]) as $sibling) {
            if ($sibling !== $except && mb_strtolower($sibling->getName()) === mb_strtolower($name)) {
                throw new \InvalidArgumentException(\sprintf('В «%s» уже есть отдел «%s» (id %d).', $parent->getName(), $sibling->getName(), $sibling->getId()));
            }
        }
    }
}
