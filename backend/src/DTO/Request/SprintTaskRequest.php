<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/org-units/{id}/sprint-tasks (weekStart: any day of the week),
 * PUT /api/sprint-tasks/{id} (full replacement; the week is fixed).
 */
final readonly class SprintTaskRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $title = '',
        #[Assert\Choice(choices: ['P1', 'P2', 'P3', 'P4', 'P5'])]
        public string $priority = 'P3',
        #[Assert\Length(max: 64)]
        public ?string $tag = null,
        public ?int $metricId = null,
        #[Assert\Range(min: 0, max: 100)]
        public int $progressPct = 0,
        #[Assert\Length(max: 4000)]
        public ?string $resultNote = null,
        #[Assert\Length(max: 1000)]
        #[Assert\Url(requireTld: false)]
        public ?string $link = null,
        #[Assert\Length(max: 4000)]
        public ?string $nextStep = null,
        #[Assert\Date]
        public ?string $weekStart = null,
    ) {
    }
}
