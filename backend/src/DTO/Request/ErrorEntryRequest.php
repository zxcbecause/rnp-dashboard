<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/org-units/{id}/errors, PUT /api/errors/{id} (full replacement).
 * Only significant mistakes: a real description and the kind of consequence are required.
 */
final readonly class ErrorEntryRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Date]
        public string $occurredOn = '',
        #[Assert\NotBlank]
        #[Assert\Length(min: 10, max: 4000, minMessage: 'Опишите ошибку подробнее (не меньше {{ limit }} символов).')]
        public string $description = '',
        #[Assert\Choice(choices: ['money', 'blocked', 'client', 'other'])]
        public string $impactType = '',
        /** EUR, decimal string; required for money impact */
        public ?string $impactAmount = null,
        #[Assert\Length(max: 4000)]
        public ?string $cause = null,
        #[Assert\Length(max: 4000)]
        public ?string $prevention = null,
        public ?int $ownerUserId = null,
        #[Assert\Choice(choices: ['open', 'in_progress', 'resolved'])]
        public string $status = 'open',
    ) {
    }
}
