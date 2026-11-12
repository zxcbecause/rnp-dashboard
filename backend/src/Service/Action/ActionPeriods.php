<?php

declare(strict_types=1);

namespace App\Service\Action;

use App\Enum\Periodicity;
use App\Service\Period\PeriodCalendar;
use App\Service\Period\WeekSegment;

/**
 * Period rules of base functions:
 *  - daily: every working day (Monday–Friday);
 *  - weekly: every month-cut week segment (same weeks as the RNP screen);
 *  - monthly / quarterly: calendar month / quarter.
 * A slot is due on the last day of its period.
 */
final readonly class ActionPeriods
{
    public function __construct(private PeriodCalendar $calendar)
    {
    }

    /**
     * Slots shown on the week screen: the days and the segment of the week, the month and the quarter it is in.
     *
     * @return list<ActionSlot>
     */
    public function displaySlots(Periodicity $periodicity, WeekSegment $week): array
    {
        return match ($periodicity) {
            Periodicity::Daily => $this->workingDays($week->start, $week->end),
            Periodicity::Weekly => [new ActionSlot($week->start, $week->end)],
            Periodicity::Monthly => [$this->monthSlot($week->start)],
            Periodicity::Quarterly => [$this->quarterSlot($week->start)],
        };
    }

    /**
     * Slots due within [from, to] (inclusive) — the base of the Execution rate.
     *
     * @return list<ActionSlot>
     */
    public function dueSlots(Periodicity $periodicity, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if (Periodicity::Daily === $periodicity) {
            return $this->workingDays($from, $to);
        }

        $slots = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $slot = $this->slotFor($periodicity, $day);
            if ($slot->due->format('Y-m-d') === $day->format('Y-m-d')) {
                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /**
     * The slot containing a day; null when the day is not a valid check date (e.g. a weekend for daily).
     */
    public function slotStartingOn(Periodicity $periodicity, \DateTimeImmutable $day): ?ActionSlot
    {
        $slot = $this->slotFor($periodicity, $day);

        return $slot->start->format('Y-m-d') === $day->format('Y-m-d')
            && (Periodicity::Daily !== $periodicity || (int) $day->format('N') <= 5)
            ? $slot
            : null;
    }

    private function slotFor(Periodicity $periodicity, \DateTimeImmutable $day): ActionSlot
    {
        return match ($periodicity) {
            Periodicity::Daily => new ActionSlot($day, $day),
            Periodicity::Weekly => (function () use ($day): ActionSlot {
                $segment = $this->calendar->weekSegmentFor($day);

                return new ActionSlot($segment->start, $segment->end);
            })(),
            Periodicity::Monthly => $this->monthSlot($day),
            Periodicity::Quarterly => $this->quarterSlot($day),
        };
    }

    /**
     * @return list<ActionSlot>
     */
    private function workingDays(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $slots = [];
        for ($day = $from->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') <= 5) {
                $slots[] = new ActionSlot($day, $day);
            }
        }

        return $slots;
    }

    private function monthSlot(\DateTimeImmutable $day): ActionSlot
    {
        $start = $day->setTime(0, 0)->modify('first day of this month');

        return new ActionSlot($start, $start->modify('last day of this month'));
    }

    private function quarterSlot(\DateTimeImmutable $day): ActionSlot
    {
        $month = intdiv((int) $day->format('n') - 1, 3) * 3 + 1;
        $start = new \DateTimeImmutable(\sprintf('%s-%02d-01', $day->format('Y'), $month));

        return new ActionSlot($start, $start->modify('+2 months')->modify('last day of this month'));
    }
}
