<?php

declare(strict_types=1);

namespace App\DTO\Admin;

use App\Entity\Org\OrgMembership;

/**
 * A membership of a unit on the administration screen.
 */
final readonly class AdminMemberDto
{
    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
        public string $role,
        public bool $isActive,
    ) {
    }

    public static function fromEntity(OrgMembership $membership): self
    {
        $user = $membership->getUser();

        return new self((int) $user->getId(), $user->getName(), $user->getEmail(), $membership->getRole()->value, $user->isActive());
    }
}
