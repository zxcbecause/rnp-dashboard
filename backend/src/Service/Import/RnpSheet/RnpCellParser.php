<?php

declare(strict_types=1);

namespace App\Service\Import\RnpSheet;

use App\Enum\MetricUnit;
use App\Service\Metric\MetricValueNormalizer;
use BcMath\Number;

/**
 * Reads a formatted sheet cell ("€3 925", "€27,8", "9,47%", "45%", "86") as a value of the metric unit.
 * Empty, "-", "-%" and "—" are "no value"; anything else unreadable (text, emoji) is an error.
 * Percent is stored as a fraction; extra decimals of averages are rounded to the unit precision,
 * but a fractional count is rejected rather than rounded.
 */
final class RnpCellParser
{
    private const array DECIMALS = ['eur' => 2, 'percent' => 4, 'minutes' => 2, 'days' => 2, 'ratio' => 4, 'count' => 0, 'boolean' => 0, 'score' => 2];

    /**
     * @throws \InvalidArgumentException
     */
    public static function parse(MetricUnit $unit, string $raw): ?Number
    {
        $value = trim((string) preg_replace('/[\s\x{00A0}\x{202F}€]+/u', '', $raw));
        // "н/п" = not applicable (e.g. no plan for a breakdown row).
        if (\in_array(mb_strtolower($value), ['', '-', '-%', '—', '–', 'н/п'], true)) {
            return null;
        }

        $percent = str_ends_with($value, '%');
        $value = str_replace(',', '.', rtrim($value, '%'));
        if (1 !== preg_match('/^-?\d{1,12}(\.\d+)?$/', $value)) {
            throw new \InvalidArgumentException(\sprintf('«%s» is not a number', mb_substr($raw, 0, 40)));
        }
        \assert(is_numeric($value));

        $number = new Number($value);
        if ($percent) {
            if (MetricUnit::Percent !== $unit) {
                throw new \InvalidArgumentException(\sprintf('«%s» is a percentage, the metric is in %s', $raw, $unit->value));
            }
            $number = $number->div(100, 6);
        } elseif (MetricUnit::Percent === $unit && $number->compare(1) > 0) {
            // "7" in a percent row is 7 %, not 700 %: without the sign only a fraction is unambiguous.
            throw new \InvalidArgumentException(\sprintf('«%s» has no "%%" sign: write it as a percentage', $raw));
        }

        $decimals = self::DECIMALS[$unit->value];
        if (0 === $decimals && 0 !== $number->round(0)->compare($number)) {
            throw new \InvalidArgumentException(\sprintf('«%s» is not a whole number', $raw));
        }

        $rounded = (string) $number->round($decimals);
        if (str_contains($rounded, '.')) {
            $rounded = rtrim(rtrim($rounded, '0'), '.');
        }

        return MetricValueNormalizer::normalize($unit, '' === $rounded || '-' === $rounded ? '0' : $rounded);
    }
}
