<?php

declare(strict_types=1);

namespace App\Enum;

enum SyncStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
}
