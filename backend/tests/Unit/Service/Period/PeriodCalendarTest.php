<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Period;

use App\Service\Period\PeriodCalendar;
use App\Service\Period\WeekSegment;
use Codeception\Test\Unit;

final class PeriodCalendarTest extends Unit
{
    private PeriodCalendar $calendar;

    protected function _before(): void
    {
        $this->calendar = new PeriodCalendar();
    }

    public function testNovember2026MatchesTheSheet(): void
    {
        // Sheet "Ноябрь": "Неделя 1: 01.11" ... "Неделя 6: 30.11".
        $weeks = $this->calendar->monthWeeks(2026, 11);

        self::assertSame(
            [
                [1, '2026-11-01', '2026-11-01'],
                [2, '2026-11-02', '2026-11-08'],
                [3, '2026-11-09', '2026-11-15'],
                [4, '2026-11-16', '2026-11-22'],
                [5, '2026-11-23', '2026-11-29'],
                [6, '2026-11-30', '2026-11-30'],
            ],
            array_map(
                static fn (WeekSegment $w): array => [$w->index, $w->start->format('Y-m-d'), $w->end->format('Y-m-d')],
                $weeks,
            ),
        );
    }

    public function testSegmentsCoverEveryDayOfTheMonthExactlyOnce(): void
    {
        foreach ([[2026, 2], [2026, 9], [2026, 10], [2028, 2]] as [$year, $month]) {
            $days = array_sum(array_map(
                static fn (WeekSegment $w): int => $w->days(),
                $this->calendar->monthWeeks($year, $month),
            ));

            self::assertSame($this->calendar->daysInMonth($year, $month), $days, "$year-$month");
        }
    }

    public function testMonthStartingOnMondayHasNoCutFirstWeek(): void
    {
        // June 2026 starts on Monday.
        $first = $this->calendar->monthWeeks(2026, 6)[0];

        self::assertSame('2026-06-01', $first->start->format('Y-m-d'));
        self::assertSame(7, $first->days());
    }

    public function testWeekSegmentForDateCrossingMonths(): void
    {
        // Week Mon 28.09 – Sun 04.10 is split: 01.10 belongs to the October segment.
        $segment = $this->calendar->weekSegmentFor(new \DateTimeImmutable('2026-10-01'));

        self::assertSame(1, $segment->index);
        self::assertSame('2026-10-01', $segment->start->format('Y-m-d'));
        self::assertSame('2026-10-04', $segment->end->format('Y-m-d'));
    }
}
