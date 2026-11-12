<?php

declare(strict_types=1);

namespace App\DTO\Ai;

use App\DTO\UserRefDto;
use App\Entity\Ai\AiAnomalyExplanation;

/**
 * POST /api/metrics/{id}/ai/anomaly: why the metric is red this week / behind for the month.
 */
final readonly class AnomalyExplanationDto
{
    public function __construct(
        public int $metricId,
        public string $metricName,
        /** "week" | "forecast" */
        public string $kind,
        /** Y-m-d: week segment start or month start */
        public string $periodStart,
        /** Markdown bullet list */
        public string $text,
        public string $createdAt,
        public ?UserRefDto $createdBy,
        /** True when the stored explanation was reused: the figures have not changed since */
        public bool $cached,
    ) {
    }

    public static function from(AiAnomalyExplanation $explanation, bool $cached): self
    {
        return new self(
            (int) $explanation->getMetric()->getId(),
            $explanation->getMetric()->getName(),
            $explanation->getKind(),
            $explanation->getPeriodStart()->format('Y-m-d'),
            $explanation->getText(),
            $explanation->getCreatedAt()->format(\DATE_ATOM),
            UserRefDto::fromNullable($explanation->getCreatedBy()),
            $cached,
        );
    }
}
