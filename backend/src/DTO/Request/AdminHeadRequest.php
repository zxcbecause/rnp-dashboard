<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * PUT /api/admin/org-units/{id}/head: null removes the head.
 */
final readonly class AdminHeadRequest
{
    public function __construct(
        #[Assert\Positive]
        public ?int $userId = null,
    ) {
    }
}
