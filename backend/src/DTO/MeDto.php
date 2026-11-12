<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Auth\User;
use App\Entity\Org\OrgMembership;
use App\Entity\Org\OrgUnit;
use App\Security\Access\AccessScope;

final readonly class MeDto
{
    /**
     * @param list<MembershipDto> $memberships
     * @param list<int>           $rootUnitIds where the user's cabinet starts
     */
    public function __construct(
        public int $id,
        public string $email,
        public string $name,
        public bool $isAdmin,
        public array $memberships,
        public array $rootUnitIds,
        /** @var list<OrgUnitDto> units whose missed calls the user can see (screen «Пропущенные звонки») */
        public array $callUnits = [],
    ) {
    }

    /**
     * @param list<OrgMembership> $memberships
     * @param list<OrgUnit>       $callUnits
     */
    public static function fromEntity(User $user, array $memberships, AccessScope $scope, array $callUnits = []): self
    {
        return new self(
            (int) $user->getId(),
            $user->getEmail(),
            $user->getName(),
            $user->isAdmin(),
            array_map(MembershipDto::fromEntity(...), $memberships),
            $scope->rootIds,
            array_map(OrgUnitDto::fromEntity(...), $callUnits),
        );
    }
}
