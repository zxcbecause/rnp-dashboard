<?php

declare(strict_types=1);

namespace App\Service\Metric;

final class RecalculationResult
{
    /** @var list<string> codes of recalculated metrics */
    public array $metrics = [];

    /** @var array<string, string> code => reason */
    public array $skipped = [];

    /** @var array<string, string> code => error */
    public array $errors = [];

    public int $written = 0;
    public int $unchanged = 0;
    public int $keptManual = 0;
}
