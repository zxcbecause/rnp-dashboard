<?php

declare(strict_types=1);

namespace App\Service\Import\RnpSheet\Model;

/**
 * One month sheet of the legacy "Метрики RNP" spreadsheet, as parsed from its CSV export.
 */
final readonly class RnpSheet
{
    /**
     * @param array<string, int> $weekFactColumns week segment start (Y-m-d) => column of "ФАКТ НЕДЕЛИ"
     * @param list<RnpSheetRow>  $rows            metric rows (sections and sub-headers excluded)
     */
    public function __construct(
        public \DateTimeImmutable $month,
        public ?int $monthPlanColumn,
        public array $weekFactColumns,
        public array $rows,
    ) {
    }
}
