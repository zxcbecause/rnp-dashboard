<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Metric\Metric;

final readonly class MetricDto
{
    public function __construct(
        public int $id,
        public int $orgUnitId,
        public string $code,
        public string $name,
        public string $unit,
        public string $direction,
        public string $timeAgg,
        public string $treeAgg,
        public ?string $formula,
        public string $sourceType,
        public bool $isManual,
        public string $granularity,
        public ?UserRefDto $owner,
        public ?UserRefDto $filler,
        public int $sort,
        public MetricCardDto $card,
        public ?string $planFormula = null,
    ) {
    }

    public static function fromEntity(Metric $metric): self
    {
        return new self(
            (int) $metric->getId(),
            (int) $metric->getOrgUnit()->getId(),
            $metric->getCode(),
            $metric->getName(),
            $metric->getUnit()->value,
            $metric->getDirection()->value,
            $metric->getTimeAgg()->value,
            $metric->getTreeAgg()->value,
            $metric->getFormula(),
            $metric->getSourceType()->value,
            $metric->isManual(),
            $metric->getGranularity()->value,
            UserRefDto::fromNullable($metric->getOwnerUser()),
            UserRefDto::fromNullable($metric->getFillerUser()),
            $metric->getSort(),
            MetricCardDto::fromEntity($metric),
            $metric->getPlanFormula(),
        );
    }
}
