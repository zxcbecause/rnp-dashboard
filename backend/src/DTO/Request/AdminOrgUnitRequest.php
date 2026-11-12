<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/admin/org-units (new unit) and PUT /api/admin/org-units/{id} (rename / move with the branch).
 */
final readonly class AdminOrgUnitRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name = '',
        #[Assert\Positive]
        public ?int $parentId = null,
    ) {
    }
}
