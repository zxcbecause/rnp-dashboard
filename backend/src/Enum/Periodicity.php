<?php

declare(strict_types=1);

namespace App\Enum;

enum Periodicity: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
}
