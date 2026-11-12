<?php

declare(strict_types=1);

namespace App\Service\Action;

/**
 * One period in which a base function must be done: a working day, a week segment, a month or a quarter.
 * `start` identifies the check (base_function_check.period_start); `due` is the last day of the period.
 */
final readonly class ActionSlot
{
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $due,
    ) {
    }

    public function key(): string
    {
        return $this->start->format('Y-m-d');
    }
}
