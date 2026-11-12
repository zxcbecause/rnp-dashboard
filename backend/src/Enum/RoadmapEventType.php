<?php

declare(strict_types=1);

namespace App\Enum;

enum RoadmapEventType: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case FieldChanged = 'field_changed';
    case EffectAssessed = 'effect_assessed';
}
