<?php

declare(strict_types=1);

namespace App\Enum;

enum ErrorImpactType: string
{
    case Money = 'money';
    case Blocked = 'blocked';
    case Client = 'client';
    case Other = 'other';
}
