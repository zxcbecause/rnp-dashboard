<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * PUT /api/admin/org-units/{id}/members: adds a member or changes its role (the head is set separately).
 */
final readonly class AdminMemberRequest
{
    public function __construct(
        #[Assert\Positive]
        public int $userId = 0,
        #[Assert\Choice(choices: ['member', 'viewer'])]
        public string $role = 'member',
    ) {
    }
}
