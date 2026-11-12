<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/call-exclusions/{id}/decision.
 */
final readonly class CallExclusionDecisionRequest
{
    public function __construct(
        public bool $approve = false,
        #[Assert\Length(max: 2000)]
        public ?string $note = null,
    ) {
    }
}
