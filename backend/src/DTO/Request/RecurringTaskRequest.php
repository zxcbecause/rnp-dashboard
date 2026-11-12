<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RecurringTaskRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $title = '',
        #[Assert\Choice(choices: ['daily', 'weekly', 'monthly', 'quarterly'])]
        public string $periodicity = 'weekly',
        public bool $isActive = true,
    ) {
    }
}
