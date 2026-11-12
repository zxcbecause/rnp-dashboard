<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Metric;

use App\Enum\MetricDirection;
use App\Service\Metric\Completion;
use App\Service\Metric\Formula\FormulaEvaluator;
use App\Service\Metric\Formula\FormulaException;
use BcMath\Number;
use Codeception\Test\Unit;

final class FormulaAndCompletionTest extends Unit
{
    public function testEvaluatesWithPrecedenceAndParentheses(): void
    {
        $values = ['a' => new Number('10'), 'b' => new Number('4'), 'c_2' => new Number('2')];
        $eval = new FormulaEvaluator();
        $resolve = static fn (string $code): ?Number => $values[$code] ?? null;

        self::assertSame('18', $this->n($eval->evaluate('a + b * c_2', $resolve)));
        self::assertSame('28', $this->n($eval->evaluate('(a + b) * c_2', $resolve)));
        self::assertSame('2.5', $this->n($eval->evaluate('a / b', $resolve)));
        self::assertSame('-6', $this->n($eval->evaluate('-a + b', $resolve)));
        self::assertSame('50', $this->n($eval->evaluate('a / b * 20', $resolve)));
        // Decimal constants: the 10 % commission of cs_direct_commission.
        self::assertSame('1', $this->n($eval->evaluate('0.1 * a', $resolve)));
        self::assertSame(['a', 'b', 'c_2'], $eval->references('a+b*(c_2-a)'));
    }

    public function testMissingOperandOrZeroDivisionGivesNoValue(): void
    {
        $eval = new FormulaEvaluator();

        self::assertNull($eval->evaluate('a / b', static fn (string $c): ?Number => 'a' === $c ? new Number('5') : null));
        self::assertNull($eval->evaluate('a / b', static fn (string $c): Number => new Number('a' === $c ? '5' : '0')));
    }

    public function testRejectsAnythingButArithmetic(): void
    {
        $eval = new FormulaEvaluator();

        foreach (['a; drop table', 'system(a)', 'a +', '(a', 'a b', 'A + b'] as $formula) {
            try {
                $eval->evaluate($formula, static fn (): Number => new Number('1'));
                self::fail("Formula accepted: $formula");
            } catch (FormulaException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPlanReferencesKeepTheSuffixForTheResolver(): void
    {
        $eval = new FormulaEvaluator();
        $seen = [];
        $resolve = static function (string $id) use (&$seen): Number {
            $seen[] = $id;

            return new Number(str_ends_with($id, '.plan') ? '0.3' : '200');
        };

        self::assertSame('60', $this->n($eval->evaluate('cs_share.plan * cs_in', $resolve)));
        self::assertSame(['cs_share.plan', 'cs_in'], $seen);
        self::assertSame(['cs_share', 'cs_in'], $eval->references('cs_share.plan * cs_in + cs_share'));
        self::assertSame('cs_share', FormulaEvaluator::code('cs_share.plan'));
        self::assertFalse(FormulaEvaluator::isPlanReference('cs_share'));

        foreach (['a.fact', 'a.plan.plan', 'a .plan', 'a.Plan'] as $formula) {
            try {
                $eval->evaluate($formula, $resolve);
                self::fail("Formula accepted: $formula");
            } catch (FormulaException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCompletionHigherBetter(): void
    {
        self::assertSame('75', $this->n(Completion::percent(new Number('200'), new Number('150'), MetricDirection::HigherBetter)));
        self::assertSame('100', $this->n(Completion::percent(new Number('0'), new Number('5'), MetricDirection::HigherBetter)));
        self::assertNull(Completion::percent(null, new Number('5'), MetricDirection::HigherBetter));
        self::assertNull(Completion::percent(new Number('5'), null, MetricDirection::HigherBetter));
    }

    public function testCompletionLowerBetterIsInverted(): void
    {
        // CPL: plan 25, fact 20 → 125 %; fact 50 → 50 %.
        self::assertSame('125', $this->n(Completion::percent(new Number('25'), new Number('20'), MetricDirection::LowerBetter)));
        self::assertSame('50', $this->n(Completion::percent(new Number('25'), new Number('50'), MetricDirection::LowerBetter)));
        // Target 0 %: met only with zero.
        self::assertSame('100', $this->n(Completion::percent(new Number('0'), new Number('0'), MetricDirection::LowerBetter)));
        self::assertSame('0', $this->n(Completion::percent(new Number('0'), new Number('0.02'), MetricDirection::LowerBetter)));
        // Zero fact with a positive plan is capped instead of dividing by zero.
        self::assertSame('200', $this->n(Completion::percent(new Number('10'), new Number('0'), MetricDirection::LowerBetter)));
        self::assertSame('200', $this->n(Completion::percent(new Number('10'), new Number('1'), MetricDirection::LowerBetter)));
    }

    public function testColors(): void
    {
        $green = new Number('100');
        $red = new Number('90');

        self::assertSame(Completion::GREEN, Completion::color(new Number('100'), $green, $red));
        self::assertSame(Completion::NEUTRAL, Completion::color(new Number('95.5'), $green, $red));
        self::assertSame(Completion::NEUTRAL, Completion::color(new Number('90'), $green, $red));
        self::assertSame(Completion::RED, Completion::color(new Number('89.99'), $green, $red));
        self::assertNull(Completion::color(null, $green, $red));
    }

    private function n(?Number $n): ?string
    {
        if (null === $n) {
            return null;
        }
        $s = (string) $n;

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
