<?php

declare(strict_types=1);

namespace App\Enum;

enum TreeAggregation: string
{
    case Sum = 'sum';
    case WeightedAvg = 'weighted_avg';
    case Formula = 'formula';
    case None = 'none';
}
