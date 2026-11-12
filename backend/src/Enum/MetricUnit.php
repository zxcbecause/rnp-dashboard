<?php

declare(strict_types=1);

namespace App\Enum;

enum MetricUnit: string
{
    case Eur = 'eur';
    case Count = 'count';
    case Percent = 'percent';
    case Minutes = 'minutes';
    case Ratio = 'ratio';
    /** Durations such as "days to launch a property" or "working days to close the month". */
    case Days = 'days';
    /** Yes/no milestones (e.g. "budget regulation approved"), stored as 1 / 0. */
    case Boolean = 'boolean';
    /** Signed index such as eNPS, from −100 to 100. */
    case Score = 'score';
}
