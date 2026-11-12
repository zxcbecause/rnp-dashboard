<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Action;

use App\Enum\Periodicity;
use App\Service\Action\ActionPeriods;
use App\Service\Action\ActionSlot;
use App\Service\Period\PeriodCalendar;
use Codeception\Test\Unit;

final class ActionPeriodsTest extends Unit
{
    private ActionPeriods $periods;
    private PeriodCalendar $calendar;

    protected function _before(): void
    {
        $this->calendar = new PeriodCalendar();
        $this->periods = new ActionPeriods($this->calendar);
    }

    public function testDisplaySlotsOfAWeek(): void
    {
        // Mon 26.10 – Sat 31.10.2026 (the October part of the week).
        $week = $this->calendar->weekSegmentFor(new \DateTimeImmutable('2026-10-28'));

        self::assertSame(['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30'], $this->keys($this->periods->displaySlots(Periodicity::Daily, $week)));
        self::assertSame(['2026-10-26'], $this->keys($this->periods->displaySlots(Periodicity::Weekly, $week)));
        self::assertSame(['2026-10-01'], $this->keys($this->periods->displaySlots(Periodicity::Monthly, $week)));
        self::assertSame(['2026-10-01'], $this->keys($this->periods->displaySlots(Periodicity::Quarterly, $week)));
        self::assertSame('2026-12-31', $this->periods->displaySlots(Periodicity::Quarterly, $week)[0]->due->format('Y-m-d'));
    }

    public function testDueSlotsCountWhereThePeriodEnds(): void
    {
        $from = new \DateTimeImmutable('2026-10-26');
        $to = new \DateTimeImmutable('2026-10-31');

        // The monthly slot of October is due on 31.10 → it belongs to the last week of October.
        self::assertSame(['2026-10-01'], $this->keys($this->periods->dueSlots(Periodicity::Monthly, $from, $to)));
        self::assertSame([], $this->keys($this->periods->dueSlots(Periodicity::Monthly, new \DateTimeImmutable('2026-10-19'), new \DateTimeImmutable('2026-10-25'))));
        self::assertSame(['2026-10-26'], $this->keys($this->periods->dueSlots(Periodicity::Weekly, $from, $to)));
        // A whole month: five October segments.
        self::assertCount(5, $this->periods->dueSlots(Periodicity::Weekly, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')));
        self::assertCount(22, $this->periods->dueSlots(Periodicity::Daily, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')));
        self::assertSame([], $this->keys($this->periods->dueSlots(Periodicity::Quarterly, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'))));
        self::assertSame(['2026-10-01'], $this->keys($this->periods->dueSlots(Periodicity::Quarterly, new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-31'))));
    }

    public function testValidCheckDates(): void
    {
        self::assertNotNull($this->periods->slotStartingOn(Periodicity::Daily, new \DateTimeImmutable('2026-10-30')));
        self::assertNull($this->periods->slotStartingOn(Periodicity::Daily, new \DateTimeImmutable('2026-10-31')), 'Saturday');
        self::assertNotNull($this->periods->slotStartingOn(Periodicity::Weekly, new \DateTimeImmutable('2026-11-01')), 'cut week starting on a Sunday');
        self::assertNull($this->periods->slotStartingOn(Periodicity::Weekly, new \DateTimeImmutable('2026-11-03')));
        self::assertNotNull($this->periods->slotStartingOn(Periodicity::Monthly, new \DateTimeImmutable('2026-11-01')));
        self::assertNull($this->periods->slotStartingOn(Periodicity::Quarterly, new \DateTimeImmutable('2026-11-01')));
        self::assertNotNull($this->periods->slotStartingOn(Periodicity::Quarterly, new \DateTimeImmutable('2026-10-01')));
    }

    /**
     * @param list<ActionSlot> $slots
     *
     * @return list<string>
     */
    private function keys(array $slots): array
    {
        return array_map(static fn (ActionSlot $s): string => $s->key(), $slots);
    }
}
