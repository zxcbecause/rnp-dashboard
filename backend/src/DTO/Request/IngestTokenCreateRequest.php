<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/admin/ingest-tokens.
 */
final readonly class IngestTokenCreateRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $name = '',
        #[Assert\Positive]
        public int $orgUnitId = 0,
    ) {
    }
}
