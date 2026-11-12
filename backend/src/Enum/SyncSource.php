<?php

declare(strict_types=1);

namespace App\Enum;

enum SyncSource: string
{
    case PlatformApi = 'platform_api';
    case Bitrix = 'bitrix';
    case PushApi = 'push_api';
    case Import = 'import';
}
