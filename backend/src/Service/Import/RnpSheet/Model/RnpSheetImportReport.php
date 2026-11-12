<?php

declare(strict_types=1);

namespace App\Service\Import\RnpSheet\Model;

/**
 * What an import did (or would do in a dry run).
 */
final class RnpSheetImportReport
{
    public int $plansWritten = 0;
    public int $plansUnchanged = 0;
    public int $factsWritten = 0;
    public int $factsUnchanged = 0;
    public int $factsKeptManual = 0;

    /** @var array<string, string> metric code => sheet row name it was taken from */
    public array $matched = [];

    /** @var list<string> "Section / row name" of data rows without a mapping */
    public array $unmapped = [];

    /** @var list<string> cells that could not be read as a value of the metric unit */
    public array $invalid = [];
}
