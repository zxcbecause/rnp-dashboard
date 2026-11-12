<?php

declare(strict_types=1);

namespace App\DTO\Rnp;

final readonly class RnpRowDto
{
    public function __construct(
        public int $metricId,
        public string $code,
        public string $name,
        public string $unit,
        public string $direction,
        public string $timeAgg,
        public string $granularity,
        public string $sourceType,
        public bool $isManual,
        public bool $isFormula,
        public bool $isRollup,
        /** "manual" | "auto" | "import" | null — origin of the stored fact of the shown period */
        public ?string $factOrigin,
        public bool $canEnterFact,
        public bool $canSetPlan,
        /** null for monthly metrics: they have no weekly value */
        public ?RnpCellDto $week,
        /** fact = month to date (up to the end of the shown week) */
        public RnpCellDto $month,
        /** month forecast for additive metrics: fact to date / elapsed days × days in month */
        public ?string $runRate,
        public ?string $runRateCompletionPct,
        public ?string $runRateColor,
        /** Hidden in the current user's view (only returned with showHidden) */
        public bool $hidden = false,
        /** Why the shown period deviated and what is done (week segment; month for monthly metrics) */
        public ?DeviationNoteDto $note = null,
        /** Whether the user may write that explanation */
        public bool $canExplain = false,
        /** Daily pace to the month plan (additive weekly metrics with a month plan, while the month runs) */
        public ?PaceDto $pace = null,
        /** Set when the month plan is computed from other metrics' facts (e.g. 30 % of incoming bookings); no run rate then */
        public ?string $planFormula = null,
        /** Average per day of the week and of the month to date, for metrics that ask for it (calls) */
        public ?DailyAverageDto $dailyAverage = null,
    ) {
    }

    public function withDeviation(?DeviationNoteDto $note, bool $canExplain): self
    {
        return new self(
            $this->metricId, $this->code, $this->name, $this->unit, $this->direction, $this->timeAgg, $this->granularity,
            $this->sourceType, $this->isManual, $this->isFormula, $this->isRollup, $this->factOrigin, $this->canEnterFact,
            $this->canSetPlan, $this->week, $this->month, $this->runRate, $this->runRateCompletionPct, $this->runRateColor,
            $this->hidden, $note, $canExplain, $this->pace, $this->planFormula, $this->dailyAverage,
        );
    }
}
