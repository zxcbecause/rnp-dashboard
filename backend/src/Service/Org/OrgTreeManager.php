<?php

declare(strict_types=1);

namespace App\Service\Org;

use App\Entity\Org\OrgUnit;
use App\Entity\Org\OrgUnitPath;
use App\Repository\Org\OrgUnitPathRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The only writer of the org closure table: creating or moving a unit always rewrites its paths.
 */
final readonly class OrgTreeManager
{
    public function __construct(
        private EntityManagerInterface $em,
        private OrgUnitPathRepository $paths,
    ) {
    }

    public function createUnit(string $name, ?OrgUnit $parent = null, int $sort = 0): OrgUnit
    {
        $unit = new OrgUnit($name, $parent);
        $unit->setSort($sort);

        $this->em->wrapInTransaction(function () use ($unit, $parent): void {
            $this->em->persist($unit);
            // Composite path keys need the unit id.
            $this->em->flush();

            $this->em->persist(new OrgUnitPath($unit, $unit, 0));

            if (null !== $parent) {
                foreach ($this->paths->findBy(['descendant' => $parent]) as $parentPath) {
                    $this->em->persist(new OrgUnitPath($parentPath->getAncestor(), $unit, $parentPath->getDepth() + 1));
                }
            }

            $this->em->flush();
        });

        return $unit;
    }

    /**
     * Moves a unit with its whole branch under another parent. Paths from the old ancestors to the branch
     * are replaced by paths from the new ones; paths inside the branch stay. Metrics, memberships and
     * ingest tokens belong to units and follow them, so access changes with the tree: the old parent's
     * head no longer sees the branch, the new one does.
     *
     * @throws \InvalidArgumentException when the target is the unit itself or inside its branch, or the unit is a root
     */
    public function moveUnit(OrgUnit $unit, OrgUnit $newParent): void
    {
        $unitId = $unit->getId() ?? throw new \InvalidArgumentException('Unsaved unit.');
        $parentId = $newParent->getId() ?? throw new \InvalidArgumentException('Unsaved unit.');
        if (null === $unit->getParent()) {
            throw new \InvalidArgumentException(\sprintf('"%s" is a root and cannot be moved.', $unit->getName()));
        }
        if ($unit->getParent() === $newParent) {
            return;
        }

        $connection = $this->em->getConnection();
        $branch = array_map(intval(...), $connection->fetchFirstColumn('SELECT descendant_id FROM org_unit_path WHERE ancestor_id = ?', [$unitId]));
        if (\in_array($parentId, $branch, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is inside the branch of "%s".', $newParent->getName(), $unit->getName()));
        }

        $this->em->wrapInTransaction(function () use ($connection, $unit, $newParent, $unitId, $parentId, $branch): void {
            $oldAncestors = array_map(intval(...), $connection->fetchFirstColumn('SELECT ancestor_id FROM org_unit_path WHERE descendant_id = ? AND depth > 0', [$unitId]));
            if ([] !== $oldAncestors) {
                $connection->executeStatement(
                    'DELETE FROM org_unit_path WHERE ancestor_id IN (?) AND descendant_id IN (?)',
                    [$oldAncestors, $branch],
                    [ArrayParameterType::INTEGER, ArrayParameterType::INTEGER],
                );
            }
            $connection->executeStatement(
                'INSERT INTO org_unit_path (ancestor_id, descendant_id, depth)
                 SELECT up.ancestor_id, down.descendant_id, up.depth + down.depth + 1
                 FROM org_unit_path up CROSS JOIN org_unit_path down
                 WHERE up.descendant_id = ? AND down.ancestor_id = ?',
                [$parentId, $unitId],
            );

            $unit->setParent($newParent);
            $this->em->flush();
        });
    }
}
