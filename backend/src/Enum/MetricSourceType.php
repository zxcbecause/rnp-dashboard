<?php

declare(strict_types=1);

namespace App\Enum;

enum MetricSourceType: string
{
    case Manual = 'manual';
    case PlatformApi = 'platform_api';
    case Bitrix = 'bitrix';
    case PushApi = 'push_api';
}
