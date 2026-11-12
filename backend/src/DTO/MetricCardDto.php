<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Metric\Metric;

/**
 * "Metric logic" card: what is measured, formula, source, period, where it is used.
 */
final readonly class MetricCardDto
{
    public function __construct(
        public ?string $whatMeasured,
        public ?string $formulaText,
        public ?string $sourceText,
        public ?string $periodText,
        public ?string $usedIn,
    ) {
    }

    public static function fromEntity(Metric $metric): self
    {
        return new self(
            $metric->getWhatMeasured(),
            $metric->getFormulaText(),
            $metric->getSourceText(),
            $metric->getPeriodText(),
            $metric->getUsedIn(),
        );
    }
}
