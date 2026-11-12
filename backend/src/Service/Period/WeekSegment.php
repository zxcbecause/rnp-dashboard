<?php

declare(strict_types=1);

namespace App\Service\Period;

/**
 * Part of a Monday–Sunday week that lies inside one calendar month.
 * Edge weeks are cut by the month boundary, e.g. November 2026: "Week 1: 01.11" (Sunday only).
 */
final readonly class WeekSegment
{
    public function __construct(
        /** 1-based number of the week inside the month. */
        public int $index,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }

    public function days(): int
    {
        return (int) $this->start->diff($this->end)->days + 1;
    }

    public function contains(\DateTimeImmutable $date): bool
    {
        $day = $date->format('Y-m-d');

        return $day >= $this->start->format('Y-m-d') && $day <= $this->end->format('Y-m-d');
    }
}
