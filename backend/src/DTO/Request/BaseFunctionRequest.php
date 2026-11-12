<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/org-units/{id}/base-functions, PUT /api/base-functions/{id} (full replacement).
 */
final readonly class BaseFunctionRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $title = '',
        #[Assert\Choice(choices: ['daily', 'weekly', 'monthly', 'quarterly'])]
        public string $periodicity = 'weekly',
        public ?int $metricId = null,
        public ?int $ownerUserId = null,
        #[Assert\Length(max: 255)]
        public ?string $dueText = null,
        public bool $isActive = true,
    ) {
    }
}
