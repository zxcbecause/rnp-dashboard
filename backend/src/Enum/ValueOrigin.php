<?php

declare(strict_types=1);

namespace App\Enum;

enum ValueOrigin: string
{
    case Manual = 'manual';
    case Auto = 'auto';
    case Import = 'import';
}
