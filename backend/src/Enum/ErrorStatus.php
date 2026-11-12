<?php

declare(strict_types=1);

namespace App\Enum;

enum ErrorStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
}
