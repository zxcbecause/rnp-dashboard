<?php

declare(strict_types=1);

namespace App\Enum;

enum RoadmapEffect: string
{
    case Affected = 'affected';
    case NotAffected = 'not_affected';
}
