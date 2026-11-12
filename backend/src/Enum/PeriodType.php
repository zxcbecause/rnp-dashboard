<?php

declare(strict_types=1);

namespace App\Enum;

enum PeriodType: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
}
