<?php

declare(strict_types=1);

namespace App\DTO\Admin;

use App\DTO\MembershipDto;
use App\Entity\Auth\User;
use App\Entity\Org\OrgMembership;

/**
 * An account on the administration screen.
 */
final readonly class AdminUserDto
{
    /**
     * @param list<MembershipDto> $memberships
     */
    public function __construct(
        public int $id,
        public string $email,
        public string $name,
        public bool $isAdmin,
        public bool $isActive,
        public string $createdAt,
        public array $memberships,
    ) {
    }

    /**
     * @param list<OrgMembership> $memberships
     */
    public static function fromEntity(User $user, array $memberships): self
    {
        return new self(
            (int) $user->getId(),
            $user->getEmail(),
            $user->getName(),
            $user->isAdmin(),
            $user->isActive(),
            $user->getCreatedAt()->format(\DATE_ATOM),
            array_map(MembershipDto::fromEntity(...), $memberships),
        );
    }
}
