<?php

declare(strict_types=1);

namespace App\Service\Import\RnpSheet;

use App\Entity\Metric\Metric;
use App\Entity\Metric\MetricPlan;
use App\Entity\Metric\MetricValue;
use App\Enum\MetricSourceType;
use App\Enum\PeriodType;
use App\Enum\TimeAggregation;
use App\Enum\ValueOrigin;
use App\Service\Import\RnpSheet\Model\RnpSheet;
use App\Service\Import\RnpSheet\Model\RnpSheetImportReport;
use App\Service\Import\RnpSheet\Model\RnpSheetRow;
use BcMath\Number;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Imports one month sheet of "Метрики РНП" into the metrics mapped in config/import/rnp_sheet.yaml:
 *
 *  - the monthly plan ("План месяца") of every mapped metric;
 *  - weekly facts ("ФАКТ НЕДЕЛИ") only of manual, stored metrics: automatic metrics keep the facts
 *    computed from the platform / Bitrix (their definitions differ from the sheet's), formulas are derived;
 *  - facts are written with origin "import"; a fact typed in the dashboard (manual, with an author)
 *    is kept unless $overwriteManual; future week segments are skipped.
 *
 * Weekly plans are not imported: the dashboard derives them from the monthly plan by days.
 */
final readonly class RnpSheetImportHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private RnpSheetReader $reader,
        private RnpSheetMapping $mapping,
    ) {
    }

    /**
     * @throws \InvalidArgumentException on an unreadable file or a broken mapping
     */
    public function import(string $path, int $year, int $month, bool $dryRun = false, bool $overwriteManual = false, ?\DateTimeImmutable $today = null): RnpSheetImportReport
    {
        $sheet = $this->reader->read($path, $year, $month, $this->mapping->sections());
        $today = ($today ?? new \DateTimeImmutable())->setTime(0, 0);
        $report = new RnpSheetImportReport();

        foreach ($sheet->rows as $row) {
            $code = $this->mapping->metricFor($row->section, $row->name);
            if (null === $code) {
                $report->unmapped[] = \sprintf('%s / %s', '' === $row->section ? '—' : $row->section, $row->name);
                continue;
            }

            $metric = $this->em->getRepository(Metric::class)->findOneBy(['code' => $code]);
            if (null === $metric) {
                throw new \InvalidArgumentException(\sprintf('Mapped metric "%s" does not exist.', $code));
            }
            if (isset($report->matched[$code])) {
                $report->invalid[] = \sprintf('line %d «%s»: %s already taken from «%s», row skipped', $row->line, $row->name, $code, $report->matched[$code]);
                continue;
            }
            $report->matched[$code] = $row->name;

            // The sheet's plan of such a metric is a snapshot of the same formula (e.g. 30 % of incoming bookings so far);
            // importing it would freeze the plan, so the dashboard keeps computing it.
            if (null === $metric->getPlanFormula()) {
                $this->importPlan($metric, $sheet, $row, $report, $dryRun);
            }
            if (MetricSourceType::Manual === $metric->getSourceType() && TimeAggregation::Formula !== $metric->getTimeAgg()) {
                $this->importFacts($metric, $sheet, $row, $report, $dryRun, $overwriteManual, $today);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        return $report;
    }

    private function importPlan(Metric $metric, RnpSheet $sheet, RnpSheetRow $row, RnpSheetImportReport $report, bool $dryRun): void
    {
        $value = $this->value($metric, $row, $sheet->monthPlanColumn, 'план месяца', $report);
        if (null === $value) {
            return;
        }

        $existing = $this->em->getRepository(MetricPlan::class)->findOneBy(['metric' => $metric, 'periodType' => PeriodType::Month, 'periodStart' => $sheet->month]);
        if (null !== $existing && 0 === $existing->getValue()->compare($value)) {
            ++$report->plansUnchanged;

            return;
        }

        ++$report->plansWritten;
        if ($dryRun) {
            return;
        }
        if (null === $existing) {
            $this->em->persist(new MetricPlan($metric, PeriodType::Month, $sheet->month, $value));
        } else {
            $existing->update($value, null);
        }
    }

    private function importFacts(Metric $metric, RnpSheet $sheet, RnpSheetRow $row, RnpSheetImportReport $report, bool $dryRun, bool $overwriteManual, \DateTimeImmutable $today): void
    {
        foreach ($sheet->weekFactColumns as $start => $column) {
            $periodStart = new \DateTimeImmutable($start);
            if ($periodStart > $today) {
                continue;
            }

            $value = $this->value($metric, $row, $column, 'факт недели '.$periodStart->format('d.m'), $report);
            if (null === $value) {
                continue;
            }

            $existing = $this->em->getRepository(MetricValue::class)->findOneBy(['metric' => $metric, 'periodType' => PeriodType::Week, 'periodStart' => $periodStart]);
            if (null !== $existing && ValueOrigin::Manual === $existing->getOrigin() && null !== $existing->getEnteredBy() && !$overwriteManual) {
                ++$report->factsKeptManual;
                continue;
            }
            if (null !== $existing && ValueOrigin::Import === $existing->getOrigin() && 0 === $existing->getValue()->compare($value)) {
                ++$report->factsUnchanged;
                continue;
            }

            ++$report->factsWritten;
            if ($dryRun) {
                continue;
            }
            if (null === $existing) {
                $this->em->persist(new MetricValue($metric, PeriodType::Week, $periodStart, $value, ValueOrigin::Import));
            } else {
                $existing->update($value, ValueOrigin::Import, null);
            }
        }
    }

    private function value(Metric $metric, RnpSheetRow $row, ?int $column, string $what, RnpSheetImportReport $report): ?Number
    {
        try {
            return RnpCellParser::parse($metric->getUnit(), $row->cell($column));
        } catch (\InvalidArgumentException $e) {
            $report->invalid[] = \sprintf('line %d «%s», %s: %s', $row->line, $row->name, $what, $e->getMessage());

            return null;
        }
    }
}
