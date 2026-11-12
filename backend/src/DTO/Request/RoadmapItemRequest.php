<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/metrics/{id}/roadmap, PUT /api/roadmap-items/{id} (full replacement; the metric is fixed).
 */
final readonly class RoadmapItemRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $title = '',
        public ?int $ownerUserId = null,
        #[Assert\Date]
        public ?string $dueDate = null,
        #[Assert\Choice(choices: ['planned', 'in_progress', 'done', 'cancelled'])]
        public string $status = 'planned',
        #[Assert\Length(max: 2000)]
        public ?string $expectedEffect = null,
    ) {
    }
}
