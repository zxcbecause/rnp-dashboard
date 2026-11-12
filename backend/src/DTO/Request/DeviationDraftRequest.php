<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/metrics/{id}/ai/deviation-draft.
 */
final readonly class DeviationDraftRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['week', 'month'])]
        public string $periodType = '',
        #[Assert\NotBlank]
        #[Assert\Date]
        public string $periodStart = '',
    ) {
    }
}
