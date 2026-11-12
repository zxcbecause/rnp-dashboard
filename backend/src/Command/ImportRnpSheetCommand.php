<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\RnpSheet\RnpSheetImportHandler;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:import:rnp-sheet', description: 'Import plans and manual facts from a CSV export of one month sheet of the legacy "Метрики РНП" spreadsheet')]
final readonly class ImportRnpSheetCommand
{
    public function __construct(private RnpSheetImportHandler $handler)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'CSV file of one month sheet (Google Sheets: File → Download → CSV)')]
        string $file,
        #[Option(description: 'Month of the sheet, Y-m (e.g. 2026-10)')]
        string $month = '',
        #[Option(description: 'Only report what would be written')]
        bool $dryRun = false,
        #[Option(description: 'Also replace facts typed in the dashboard')]
        bool $overwriteManual = false,
        #[Option(description: 'List the unmapped sheet rows')]
        bool $showUnmapped = false,
    ): int {
        if (1 !== preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
            $io->error('--month=Y-m is required (e.g. --month=2026-10).');

            return Command::INVALID;
        }

        try {
            $report = $this->handler->import($file, (int) $m[1], (int) $m[2], $dryRun, $overwriteManual);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->title(\sprintf('%s %s', $dryRun ? 'Dry run of' : 'Imported', $month));
        $io->table(['Metric', 'Sheet row'], array_map(null, array_keys($report->matched), array_values($report->matched)));
        $io->listing([
            \sprintf('plans: %d written, %d unchanged', $report->plansWritten, $report->plansUnchanged),
            \sprintf('facts: %d written, %d unchanged, %d typed in the dashboard kept', $report->factsWritten, $report->factsUnchanged, $report->factsKeptManual),
            \sprintf('unmapped rows: %d%s', \count($report->unmapped), $showUnmapped || [] === $report->unmapped ? '' : ' (--show-unmapped to list)'),
        ]);

        if ($showUnmapped && [] !== $report->unmapped) {
            $io->section('Unmapped rows');
            $io->listing($report->unmapped);
        }
        if ([] !== $report->invalid) {
            $io->section('Skipped cells');
            $io->listing($report->invalid);
        }

        return Command::SUCCESS;
    }
}
