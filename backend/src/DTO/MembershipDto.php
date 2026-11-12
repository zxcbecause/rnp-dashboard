<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Org\OrgMembership;

final readonly class MembershipDto
{
    public function __construct(
        public int $orgUnitId,
        public string $orgUnitName,
        public string $role,
    ) {
    }

    public static function fromEntity(OrgMembership $membership): self
    {
        return new self(
            (int) $membership->getOrgUnit()->getId(),
            $membership->getOrgUnit()->getName(),
            $membership->getRole()->value,
        );
    }
}
