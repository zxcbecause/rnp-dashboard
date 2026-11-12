<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Auth\User;

final readonly class UserRefDto
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }

    public static function fromEntity(User $user): self
    {
        return new self((int) $user->getId(), $user->getName());
    }

    public static function fromNullable(?User $user): ?self
    {
        return null === $user ? null : self::fromEntity($user);
    }
}
