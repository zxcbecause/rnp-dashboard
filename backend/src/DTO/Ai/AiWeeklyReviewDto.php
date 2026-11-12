<?php

declare(strict_types=1);

namespace App\DTO\Ai;

use App\DTO\UserRefDto;
use App\Entity\Ai\AiWeeklyReview;

final readonly class AiWeeklyReviewDto
{
    public function __construct(
        /** False when no API key is configured: the UI explains instead of offering a button */
        public bool $available,
        public string $weekStart,
        /** Markdown, null until generated */
        public ?string $text,
        public ?string $createdAt,
        public ?UserRefDto $createdBy,
    ) {
    }

    public static function from(bool $available, string $weekStart, ?AiWeeklyReview $review): self
    {
        return new self(
            $available,
            $weekStart,
            $review?->getText(),
            $review?->getCreatedAt()->format(\DATE_ATOM),
            UserRefDto::fromNullable($review?->getCreatedBy()),
        );
    }
}
