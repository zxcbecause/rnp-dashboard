<?php

declare(strict_types=1);

namespace App\Service\Metric;

use App\DTO\MetricDto;
use App\Entity\Metric\Metric;
use App\Repository\Metric\MetricRepository;
use App\Service\Org\OrgUnitFindHandler;

/**
 * Active metrics of one visible org unit.
 */
final readonly class MetricListByUnitHandler
{
    public function __construct(
        private OrgUnitFindHandler $findUnit,
        private MetricRepository $metrics,
    ) {
    }

    /**
     * @return list<MetricDto>
     */
    public function handle(int $orgUnitId): array
    {
        $unit = $this->findUnit->handle($orgUnitId);

        return array_map(
            static fn (Metric $metric): MetricDto => MetricDto::fromEntity($metric),
            $this->metrics->findActiveByOrgUnits([(int) $unit->getId()]),
        );
    }
}
