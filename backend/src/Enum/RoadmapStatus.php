<?php

declare(strict_types=1);

namespace App\Enum;

enum RoadmapStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
