<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * PUT /api/admin/users/{id}: full replacement of the editable fields.
 */
final readonly class AdminUserUpdateRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name = '',
        public bool $isAdmin = false,
        public bool $isActive = true,
    ) {
    }
}
