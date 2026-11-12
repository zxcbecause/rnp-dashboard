<?php

declare(strict_types=1);

namespace App\Service\Org;

use App\DTO\OrgUnitDto;
use App\Entity\Org\OrgUnit;
use App\Repository\Org\OrgUnitRepository;
use App\Security\Access\AccessScopeResolver;

/**
 * Org units visible to the current user (flat list; the client builds the tree from parentId).
 */
final readonly class OrgUnitListHandler
{
    public function __construct(
        private OrgUnitRepository $units,
        private AccessScopeResolver $scopes,
    ) {
    }

    /**
     * @return list<OrgUnitDto>
     */
    public function handle(): array
    {
        $scope = $this->scopes->current();

        $units = $scope->all
            ? $this->units->findBy([], ['sort' => 'ASC', 'id' => 'ASC'])
            : $this->units->findByIdsOrdered($scope->unitIds);

        return array_map(static fn (OrgUnit $unit): OrgUnitDto => OrgUnitDto::fromEntity($unit), $units);
    }
}
