<?php

declare(strict_types=1);

namespace App\DTO\Admin;

use App\Entity\Org\OrgMembership;
use App\Entity\Org\OrgUnit;

/**
 * A unit of the org tree on the administration screen (flat list; parentId builds the tree).
 */
final readonly class AdminOrgUnitDto
{
    /**
     * @param list<AdminMemberDto> $members
     */
    public function __construct(
        public int $id,
        public ?int $parentId,
        public string $name,
        public int $sort,
        public ?int $headUserId,
        public int $activeMetrics,
        public array $members,
    ) {
    }

    /**
     * @param list<OrgMembership> $memberships
     */
    public static function fromEntity(OrgUnit $unit, array $memberships, int $activeMetrics): self
    {
        return new self(
            (int) $unit->getId(),
            $unit->getParent()?->getId(),
            $unit->getName(),
            $unit->getSort(),
            $unit->getHeadUser()?->getId(),
            $activeMetrics,
            array_map(AdminMemberDto::fromEntity(...), $memberships),
        );
    }
}
