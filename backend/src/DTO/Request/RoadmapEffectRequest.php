<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/roadmap-items/{id}/effect: "did it affect the metric".
 */
final readonly class RoadmapEffectRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['affected', 'not_affected'])]
        public string $effect = '',
        #[Assert\Length(max: 2000)]
        public ?string $note = null,
    ) {
    }
}
