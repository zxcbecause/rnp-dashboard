<?php

declare(strict_types=1);

namespace App\Service\Period;

/**
 * Period rules of the RNP sheet: weeks run Monday–Sunday and belong to a month by their days,
 * so a week crossing a month boundary is split into two segments (one per month).
 * A weekly period is identified by the segment start date.
 */
final class PeriodCalendar
{
    /**
     * @return list<WeekSegment>
     */
    public function monthWeeks(int $year, int $month): array
    {
        $first = $this->monthStart($year, $month);
        $last = $first->modify('last day of this month');

        $segments = [];
        $start = $first;
        $index = 1;

        while ($start <= $last) {
            // Days until Sunday: N is 1 (Mon) .. 7 (Sun).
            $sunday = $start->modify(\sprintf('+%d days', 7 - (int) $start->format('N')));
            $end = $sunday < $last ? $sunday : $last;

            $segments[] = new WeekSegment($index++, $start, $end);
            $start = $end->modify('+1 day');
        }

        return $segments;
    }

    public function weekSegmentFor(\DateTimeImmutable $date): WeekSegment
    {
        $day = $date->setTime(0, 0);

        foreach ($this->monthWeeks((int) $day->format('Y'), (int) $day->format('n')) as $segment) {
            if ($segment->contains($day)) {
                return $segment;
            }
        }

        throw new \LogicException('Every date belongs to a week segment of its month.');
    }

    public function monthStart(int $year, int $month): \DateTimeImmutable
    {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException(\sprintf('Invalid month %d.', $month));
        }

        return new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $month));
    }

    public function daysInMonth(int $year, int $month): int
    {
        return (int) $this->monthStart($year, $month)->format('t');
    }
}
