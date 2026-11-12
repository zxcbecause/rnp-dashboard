<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * PUT /api/base-functions/{id}/checks. done = null removes the mark.
 */
final readonly class BaseFunctionCheckRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Date]
        public string $periodStart = '',
        public ?bool $done = null,
        #[Assert\Length(max: 1000)]
        public ?string $note = null,
    ) {
    }
}
