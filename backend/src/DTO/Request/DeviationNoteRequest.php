<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * PUT /api/metrics/{id}/deviation-note. An empty reason removes the note.
 */
final readonly class DeviationNoteRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['week', 'month'])]
        public string $periodType = '',
        #[Assert\NotBlank]
        #[Assert\Date]
        public string $periodStart = '',
        #[Assert\Length(max: 2000)]
        public ?string $reason = null,
        #[Assert\Length(max: 2000)]
        public ?string $action = null,
    ) {
    }
}
