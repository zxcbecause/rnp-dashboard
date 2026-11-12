<?php

declare(strict_types=1);

namespace App\Enum;

enum OrgRole: string
{
    case Head = 'head';
    case Member = 'member';
    case Viewer = 'viewer';
    case Admin = 'admin';
}
