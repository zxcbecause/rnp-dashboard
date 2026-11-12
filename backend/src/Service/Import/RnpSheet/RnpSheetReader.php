<?php

declare(strict_types=1);

namespace App\Service\Import\RnpSheet;

use App\Service\Import\RnpSheet\Model\RnpSheet;
use App\Service\Import\RnpSheet\Model\RnpSheetRow;
use App\Service\Period\PeriodCalendar;

/**
 * Parses the CSV export of one month sheet of "Метрики РНП".
 *
 * Layout (the column set differs from month to month, so columns are found by their headers in row 2):
 *  - column B: metric name; a row with only column B filled is a section ("Marketing") or a sub-header;
 *  - "План" / "План месяца": monthly plan;
 *  - day columns "пн 05.10" ... followed by "ФАКТ НЕДЕЛИ ..." of that week segment (cut by the month boundary).
 */
final readonly class RnpSheetReader
{
    private const string DAY_HEADER = '/^(пн|вт|ср|чт|пт|сб|вс)\s+(\d{2})\.(\d{2})$/u';

    public function __construct(private PeriodCalendar $calendar)
    {
    }

    /**
     * @param list<string> $sections department names that open a section (case-insensitive)
     *
     * @throws \InvalidArgumentException when the file does not look like a month sheet
     */
    public function read(string $path, int $year, int $month, array $sections): RnpSheet
    {
        $lines = $this->csv($path);
        if (\count($lines) < 3) {
            throw new \InvalidArgumentException('The file has no header rows.');
        }

        $monthStart = $this->calendar->monthStart($year, $month);
        [$planColumn, $weeks] = $this->columns($lines[1], $monthStart);

        $known = array_map(static fn (string $s): string => mb_strtolower($s), $sections);
        $section = '';
        $rows = [];
        foreach (\array_slice($lines, 2, preserve_keys: true) as $index => $cells) {
            $name = trim((string) preg_replace('/\s+/u', ' ', $cells[1] ?? ''));
            if ('' === $name) {
                continue;
            }

            $filled = array_filter($cells, static fn (string $cell): bool => '' !== trim($cell));
            if ([1] === array_keys($filled)) {
                if (\in_array(mb_strtolower($name), $known, true)) {
                    $section = $name;
                }
                continue;
            }

            $rows[] = new RnpSheetRow($index + 1, $section, $name, $cells);
        }

        return new RnpSheet($monthStart, $planColumn, $weeks, $rows);
    }

    /**
     * @param list<string> $header the second header row
     *
     * @return array{?int, array<string, int>}
     */
    private function columns(array $header, \DateTimeImmutable $monthStart): array
    {
        $planColumn = null;
        $weeks = [];
        $firstDay = null;

        foreach ($header as $column => $raw) {
            $title = trim((string) preg_replace('/\s+/u', ' ', $raw));

            if (null === $planColumn && 1 === preg_match('/^План(\s|$)/u', $title)) {
                $planColumn = $column;
            } elseif (1 === preg_match(self::DAY_HEADER, $title, $m)) {
                if ((int) $m[3] !== (int) $monthStart->format('n')) {
                    throw new \InvalidArgumentException(\sprintf('Column "%s" is not in %s: wrong --month?', $title, $monthStart->format('Y-m')));
                }
                $firstDay ??= $monthStart->setDate((int) $monthStart->format('Y'), (int) $m[3], (int) $m[2]);
            } elseif (str_starts_with($title, 'ФАКТ') && null !== $firstDay) {
                $weeks[$this->calendar->weekSegmentFor($firstDay)->start->format('Y-m-d')] = $column;
                $firstDay = null;
            }
        }

        if ([] === $weeks) {
            throw new \InvalidArgumentException('No week columns ("пн 05.10" … "ФАКТ НЕДЕЛИ") found in the second header row.');
        }

        return [$planColumn, $weeks];
    }

    /**
     * @return list<list<string>>
     */
    private function csv(string $path): array
    {
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            throw new \InvalidArgumentException(\sprintf('Cannot read "%s".', $path));
        }

        $lines = [];
        try {
            while (false !== ($cells = fgetcsv($handle, escape: ''))) {
                $lines[] = array_map(static fn ($cell): string => (string) $cell, $cells);
            }
        } finally {
            fclose($handle);
        }

        // Google Sheets exports UTF-8 with a BOM in some locales.
        if (isset($lines[0][0])) {
            $lines[0][0] = (string) preg_replace('/^\x{FEFF}/u', '', $lines[0][0]);
        }

        return $lines;
    }
}
