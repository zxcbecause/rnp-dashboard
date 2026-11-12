<?php

declare(strict_types=1);

namespace App\Enum;

enum MetricDirection: string
{
    case HigherBetter = 'higher_better';
    case LowerBetter = 'lower_better';
}
