<?php

declare(strict_types=1);

namespace App\Service\Metric;

use App\Enum\MetricUnit;
use BcMath\Number;

/**
 * Strict typing of manual input by the metric unit. The old sheet held "€35 000", "🔴 : 48%"
 * and #REF! in number cells; here only plain decimals pass ("." as separator, no spaces/symbols).
 * Percent values are fractions (45 % → "0.45").
 */
final class MetricValueNormalizer
{
    /** @var array<string, array{int, ?string, ?string, string}> unit => [max decimals, min, max, human rule] */
    private const array RULES = [
        'eur' => [2, null, null, 'сумма в евро, до 2 знаков после точки'],
        'count' => [0, '0', null, 'целое число ≥ 0'],
        'percent' => [4, '0', '10', 'доля от 0 до 10 (45 % = 0.45), до 4 знаков'],
        'minutes' => [2, '0', null, 'число ≥ 0, до 2 знаков'],
        'days' => [2, '0', null, 'число ≥ 0, до 2 знаков'],
        'ratio' => [4, '0', null, 'число ≥ 0, до 4 знаков'],
        'score' => [2, '-100', '100', 'число от −100 до 100, до 2 знаков'],
        'boolean' => [0, '0', '1', '0 (нет) или 1 (да)'],
    ];

    /**
     * @throws \InvalidArgumentException with a user-facing message
     */
    public static function normalize(MetricUnit $unit, string|int $raw): Number
    {
        [$decimals, $min, $max, $rule] = self::RULES[$unit->value];
        $value = trim((string) $raw);

        $pattern = 0 === $decimals ? '/^-?\d{1,12}$/' : \sprintf('/^-?\d{1,12}(\.\d{1,%d})?$/', $decimals);
        if (1 !== preg_match($pattern, $value)) {
            throw new \InvalidArgumentException(\sprintf('Неверное значение «%s»: ожидается %s.', mb_substr($value, 0, 40), $rule));
        }

        \assert(is_numeric($value));
        $number = new Number($value);
        if ((null !== $min && $number->compare($min) < 0) || (null !== $max && $number->compare($max) > 0)) {
            throw new \InvalidArgumentException(\sprintf('Значение вне диапазона: ожидается %s.', $rule));
        }

        return $number;
    }
}
