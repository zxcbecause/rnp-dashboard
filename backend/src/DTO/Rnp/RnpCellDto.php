<?php

declare(strict_types=1);

namespace App\DTO\Rnp;

use BcMath\Number;

/**
 * Plan / fact / completion of one period. Numbers are decimal strings (percent metrics as
 * fractions, e.g. "0.4500"); completionPct is in percent points ("95.5").
 */
final readonly class RnpCellDto
{
    public function __construct(
        public ?string $plan,
        public ?string $fact,
        public ?string $completionPct,
        public ?string $color,
    ) {
    }

    public static function decimal(?Number $value, int $scale = 4): ?string
    {
        if (null === $value) {
            return null;
        }

        $rounded = (string) $value->round($scale);
        if (str_contains($rounded, '.')) {
            $rounded = rtrim(rtrim($rounded, '0'), '.');
        }

        return '-0' === $rounded ? '0' : $rounded;
    }
}
