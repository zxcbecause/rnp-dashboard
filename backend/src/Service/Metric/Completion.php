<?php

declare(strict_types=1);

namespace App\Service\Metric;

use App\Enum\MetricDirection;
use BcMath\Number;

/**
 * Plan completion and colour rules of the RNP screen.
 *
 *  - higher_better: fact / plan × 100; plan 0 → 100 (nothing was required);
 *  - lower_better (inverted): plan / fact × 100; plan 0 → 100 when fact is 0, otherwise 0
 *    ("target 0%" metrics); fact 0 with a positive plan → capped at MAX;
 *  - no plan or no fact → no completion, no colour.
 */
final class Completion
{
    public const string GREEN = 'green';
    public const string RED = 'red';
    public const string NEUTRAL = 'neutral';

    /** Upper bound shown for "infinitely good" lower_better results (fact 0). */
    private const string MAX = '200';

    public static function percent(?Number $plan, ?Number $fact, MetricDirection $direction): ?Number
    {
        if (null === $plan || null === $fact) {
            return null;
        }

        $planIsZero = 0 === $plan->compare(0);

        if (MetricDirection::HigherBetter === $direction) {
            return $planIsZero ? new Number('100') : ($fact * 100)->div($plan, 10);
        }

        if ($planIsZero) {
            return new Number(0 === $fact->compare(0) ? '100' : '0');
        }

        if ($fact->compare(0) <= 0) {
            return new Number(self::MAX);
        }

        $value = ($plan * 100)->div($fact, 10);

        return $value->compare(self::MAX) > 0 ? new Number(self::MAX) : $value;
    }

    public static function color(?Number $percent, Number $greenFrom, Number $redBelow): ?string
    {
        if (null === $percent) {
            return null;
        }

        return match (true) {
            $percent->compare($greenFrom) >= 0 => self::GREEN,
            $percent->compare($redBelow) < 0 => self::RED,
            default => self::NEUTRAL,
        };
    }
}
