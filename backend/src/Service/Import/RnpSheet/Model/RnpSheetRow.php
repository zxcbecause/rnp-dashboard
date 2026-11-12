<?php

declare(strict_types=1);

namespace App\Service\Import\RnpSheet\Model;

/**
 * A metric row of the sheet: its department section, its name (column B) and all cells.
 */
final readonly class RnpSheetRow
{
    /**
     * @param list<string> $cells
     */
    public function __construct(
        public int $line,
        public string $section,
        public string $name,
        public array $cells,
    ) {
    }

    public function cell(?int $column): string
    {
        return null === $column ? '' : trim($this->cells[$column] ?? '');
    }
}
