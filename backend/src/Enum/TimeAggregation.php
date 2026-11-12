<?php

declare(strict_types=1);

namespace App\Enum;

enum TimeAggregation: string
{
    case Sum = 'sum';
    case Avg = 'avg';
    case Last = 'last';
    case Formula = 'formula';
    case MonthlyCohort = 'monthly_cohort';
}
