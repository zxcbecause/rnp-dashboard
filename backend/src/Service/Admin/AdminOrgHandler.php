<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\DTO\Admin\AdminOrgUnitDto;
use App\DTO\Request\AdminHeadRequest;
use App\DTO\Request\AdminMemberRequest;
use App\DTO\Request\AdminOrgUnitRequest;
use App\Entity\Auth\User;
use App\Entity\Metric\Metric;
use App\Entity\Org\OrgMembership;
use App\Entity\Org\OrgUnit;
use App\Enum\OrgRole;
use App\Service\Org\OrgStructureManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The org tree on the administration screen (ROLE_ADMIN; the same rules as app:org, see OrgStructureManager).
 * Access follows the tree at once: heads see their branch, members and viewers their unit.
 */
final readonly class AdminOrgHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private OrgStructureManager $structure,
    ) {
    }

    /**
     * @return list<AdminOrgUnitDto> every unit, ordered by sort and id (parentId builds the tree)
     */
    public function list(): array
    {
        $members = [];
        foreach ($this->em->getRepository(OrgMembership::class)->findAll() as $membership) {
            $members[(int) $membership->getOrgUnit()->getId()][] = $membership;
        }
        $order = static fn (OrgMembership $m): array => [OrgRole::Head !== $m->getRole(), mb_strtolower($m->getUser()->getName())];
        foreach ($members as $unitId => $list) {
            usort($list, static fn (OrgMembership $a, OrgMembership $b): int => $order($a) <=> $order($b));
            $members[$unitId] = $list;
        }

        /** @var list<array{unit: int|string|null, n: int|string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(m.orgUnit) AS unit', 'COUNT(m.id) AS n')
            ->from(Metric::class, 'm')
            ->where('m.isActive = true')
            ->groupBy('m.orgUnit')
            ->getQuery()
            ->getArrayResult();
        $metrics = [];
        foreach ($rows as $row) {
            $metrics[(int) $row['unit']] = (int) $row['n'];
        }

        $units = $this->em->getRepository(OrgUnit::class)->findBy([], ['sort' => 'ASC', 'id' => 'ASC']);

        return array_map(
            static fn (OrgUnit $unit): AdminOrgUnitDto => AdminOrgUnitDto::fromEntity($unit, $members[(int) $unit->getId()] ?? [], $metrics[(int) $unit->getId()] ?? 0),
            $units,
        );
    }

    /**
     * A new unit goes after its future siblings.
     */
    public function create(AdminOrgUnitRequest $request): AdminOrgUnitDto
    {
        $parent = $this->parent($request->parentId);
        $sort = 0;
        foreach ($this->em->getRepository(OrgUnit::class)->findBy(['parent' => $parent]) as $sibling) {
            $sort = max($sort, $sibling->getSort() + 10);
        }
        try {
            $unit = $this->structure->createUnit($request->name, $parent, $sort);
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $this->dto($unit);
    }

    /**
     * Renames and, when the parent changes, moves the unit with its branch (the root keeps no parent).
     */
    public function update(int $id, AdminOrgUnitRequest $request): AdminOrgUnitDto
    {
        $unit = $this->unit($id);
        $isRoot = null === $unit->getParent();
        if ($isRoot && null !== $request->parentId) {
            throw new UnprocessableEntityHttpException('Корневой отдел нельзя перенести.');
        }
        $parent = $isRoot ? null : $this->parent($request->parentId);

        try {
            $this->structure->update($unit, $request->name, $parent);
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $this->dto($unit);
    }

    public function setHead(int $id, AdminHeadRequest $request): AdminOrgUnitDto
    {
        $unit = $this->unit($id);
        $this->structure->setHead($unit, null === $request->userId ? null : $this->user($request->userId));

        return $this->dto($unit);
    }

    public function setMember(int $id, AdminMemberRequest $request): AdminOrgUnitDto
    {
        $unit = $this->unit($id);
        $this->structure->setMember($unit, $this->user($request->userId), OrgRole::from($request->role));

        return $this->dto($unit);
    }

    public function removeMember(int $id, int $userId): AdminOrgUnitDto
    {
        $unit = $this->unit($id);
        $user = $this->em->find(User::class, $userId) ?? throw new NotFoundHttpException('Пользователь не найден.');
        try {
            $this->structure->removeMember($unit, $user);
        } catch (\InvalidArgumentException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        return $this->dto($unit);
    }

    private function dto(OrgUnit $unit): AdminOrgUnitDto
    {
        foreach ($this->list() as $dto) {
            if ($dto->id === $unit->getId()) {
                return $dto;
            }
        }

        throw new NotFoundHttpException('Отдел не найден.');
    }

    private function unit(int $id): OrgUnit
    {
        return $this->em->find(OrgUnit::class, $id) ?? throw new NotFoundHttpException('Отдел не найден.');
    }

    private function parent(?int $id): OrgUnit
    {
        if (null === $id) {
            throw new UnprocessableEntityHttpException('Укажите, в какой отдел входит этот.');
        }

        return $this->em->find(OrgUnit::class, $id) ?? throw new UnprocessableEntityHttpException('Родительский отдел не найден.');
    }

    /**
     * Only active accounts get a role: a deactivated one is switched on first (on the users tab).
     */
    private function user(int $id): User
    {
        $user = $this->em->find(User::class, $id) ?? throw new UnprocessableEntityHttpException('Пользователь не найден.');
        if (!$user->isActive()) {
            throw new UnprocessableEntityHttpException(\sprintf('%s отключён — сначала включите учётку.', $user->getEmail()));
        }

        return $user;
    }
}
