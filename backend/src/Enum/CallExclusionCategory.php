<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why an incoming call does not belong to the statistics (Gleb, 11.11.2026).
 */
enum CallExclusionCategory: string
{
    /** Technical problem: internet or telephony down. */
    case Technical = 'technical';
    case Spam = 'spam';
    case Test = 'test';
    /** A call of our own staff. */
    case Own = 'own';
    case Other = 'other';
}
