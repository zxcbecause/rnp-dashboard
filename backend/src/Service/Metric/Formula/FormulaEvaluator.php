<?php

declare(strict_types=1);

namespace App\Service\Metric\Formula;

use BcMath\Number;

/**
 * Safe evaluator for metric formulas: metric codes, decimal literals, + - * / and parentheses.
 * A code may carry the ".plan" suffix (plan formulas: the explicitly set plan of that metric); the resolver
 * receives the identifier as written. No eval(), no functions. A missing operand or a division by zero yields null ("no value"),
 * so ratios are never invented from incomplete data.
 */
final class FormulaEvaluator
{
    private const int SCALE = 10;
    private const string PLAN_SUFFIX = '.plan';

    /** @var array<string, list<array{string, string}>> parsed token cache */
    private array $tokens = [];

    /**
     * @return list<string> metric codes referenced by the formula
     */
    public function references(string $formula): array
    {
        $codes = [];
        foreach ($this->tokenize($formula) as [$type, $value]) {
            if ('id' === $type) {
                $codes[self::code($value)] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * @param callable(string): ?Number $resolve value of a metric code
     */
    public function evaluate(string $formula, callable $resolve): ?Number
    {
        $tokens = $this->tokenize($formula);
        $pos = 0;
        $result = $this->expression($tokens, $pos, $resolve);

        if ($pos !== \count($tokens)) {
            throw new FormulaException(\sprintf('Unexpected "%s" in formula "%s".', $tokens[$pos][1], $formula));
        }

        return $result;
    }

    /**
     * @param list<array{string, string}> $tokens
     * @param callable(string): ?Number   $resolve
     */
    private function expression(array $tokens, int &$pos, callable $resolve): ?Number
    {
        $left = $this->term($tokens, $pos, $resolve);

        while (isset($tokens[$pos]) && \in_array($tokens[$pos][1], ['+', '-'], true)) {
            $op = $tokens[$pos++][1];
            $right = $this->term($tokens, $pos, $resolve);
            $left = null === $left || null === $right ? null : ('+' === $op ? $left + $right : $left - $right);
        }

        return $left;
    }

    /**
     * @param list<array{string, string}> $tokens
     * @param callable(string): ?Number   $resolve
     */
    private function term(array $tokens, int &$pos, callable $resolve): ?Number
    {
        $left = $this->factor($tokens, $pos, $resolve);

        while (isset($tokens[$pos]) && \in_array($tokens[$pos][1], ['*', '/'], true)) {
            $op = $tokens[$pos++][1];
            $right = $this->factor($tokens, $pos, $resolve);

            if (null === $left || null === $right) {
                $left = null;
            } elseif ('*' === $op) {
                $left = $left * $right;
            } else {
                $left = 0 === $right->compare(0) ? null : $left->div($right, self::SCALE);
            }
        }

        return $left;
    }

    /**
     * @param list<array{string, string}> $tokens
     * @param callable(string): ?Number   $resolve
     */
    private function factor(array $tokens, int &$pos, callable $resolve): ?Number
    {
        [$type, $value] = $tokens[$pos++] ?? throw new FormulaException('Unexpected end of formula.');

        return match (true) {
            'num' === $type && is_numeric($value) => new Number($value),
            'id' === $type => $resolve($value),
            '-' === $value => (fn (?Number $n): ?Number => null === $n ? null : -$n)($this->factor($tokens, $pos, $resolve)),
            '(' === $value => $this->parenthesised($tokens, $pos, $resolve),
            default => throw new FormulaException(\sprintf('Unexpected "%s".', $value)),
        };
    }

    /**
     * @param list<array{string, string}> $tokens
     * @param callable(string): ?Number   $resolve
     */
    private function parenthesised(array $tokens, int &$pos, callable $resolve): ?Number
    {
        $value = $this->expression($tokens, $pos, $resolve);
        if (')' !== ($tokens[$pos][1] ?? null)) {
            throw new FormulaException('Missing ")".');
        }
        ++$pos;

        return $value;
    }

    /**
     * @return list<array{string, string}> [type, value], type: id | num | op
     */
    private function tokenize(string $formula): array
    {
        if (isset($this->tokens[$formula])) {
            return $this->tokens[$formula];
        }

        if (0 === preg_match_all('/\s*(?:([a-z][a-z0-9_]*(?:\.plan)?)|(\d+(?:\.\d+)?)|([-+*\/()]))\s*/A', $formula, $m, \PREG_SET_ORDER)
            || implode('', array_column($m, 0)) !== $formula) {
            throw new FormulaException(\sprintf('Invalid formula "%s".', $formula));
        }

        $tokens = [];
        foreach ($m as $match) {
            $tokens[] = match (true) {
                '' !== ($match[1] ?? '') => ['id', $match[1]],
                '' !== ($match[2] ?? '') => ['num', $match[2]],
                default => ['op', $match[3] ?? ''],
            };
        }

        return $this->tokens[$formula] = $tokens;
    }

    /**
     * Metric code of an identifier: "cs_incoming_sum.plan" → "cs_incoming_sum".
     */
    public static function code(string $identifier): string
    {
        return str_ends_with($identifier, self::PLAN_SUFFIX) ? substr($identifier, 0, -\strlen(self::PLAN_SUFFIX)) : $identifier;
    }

    public static function isPlanReference(string $identifier): bool
    {
        return str_ends_with($identifier, self::PLAN_SUFFIX);
    }
}
