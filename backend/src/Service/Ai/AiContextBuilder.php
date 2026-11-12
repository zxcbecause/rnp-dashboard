<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\DTO\Action\ActionsWeekDto;
use App\DTO\Action\MissedItemDto;
use App\DTO\Action\RoadmapItemDto;
use App\DTO\Rnp\RnpCellDto;
use App\DTO\Rnp\RnpRowDto;
use App\DTO\Rnp\RnpWeekDto;
use App\Entity\Auth\User;
use App\Service\Action\ActionsWeekHandler;
use App\Service\Rnp\RnpWeekHandler;

/**
 * Compact, model-friendly views of the data the user can already see on the screens. Everything goes through
 * the regular handlers with the user's own access scope: the model never sees more than the person asking.
 * Only aggregates and metric names leave the service; guest data does not exist here.
 */
final readonly class AiContextBuilder
{
    /** Units of the stored values, told to the model so it reads them right. */
    public const string VALUE_NOTES = 'Значения — десятичные строки. unit=percent хранится долей (0.45 = 45 %), eur — евро, '
        .'count — штуки, minutes — минуты. completionPct — % выполнения плана (для direction=lower_better инвертирован: '
        .'план / факт). status: green — план выполнен, neutral — риск (между порогами), red — не выполнен, null — нет плана. '
        .'fact = null при наличии плана — факт не внесён. Неделя — сегмент пн–вс, обрезанный границей месяца; '
        .'month.fact — факт месяца на конец показанной недели; runRate — прогноз месяца для суммируемых метрик. '
        .'planFormula — план считается формулой от фактов того же периода (например, 30 % от входящих): у будущих '
        .'периодов плана ещё нет и он появится вместе с фактом, задавать его вручную не нужно. factPrevYear недели — '
        .'сегмент прошлого года с той же датой, он бывает короче недели (1–6 дней): сравнивай осторожно.';

    public function __construct(
        private RnpWeekHandler $rnp,
        private ActionsWeekHandler $actions,
    ) {
    }

    /**
     * RNP week of a unit with the previous week's facts, execution rate and open problems.
     *
     * @return array{week: RnpWeekDto, context: array<string, mixed>}
     */
    public function week(int $unitId, ?string $week, User $user): array
    {
        // Every active metric, whatever the user hid in the personal view: reviews and explanations are stored
        // and shown to everyone who sees the unit.
        $current = $this->rnp->handle($unitId, $week, $user, showHidden: true);
        $previous = null === $current->prevWeekStart ? null : $this->rnp->handle($unitId, $current->prevWeekStart, $user, showHidden: true);
        $actions = $this->actions->handle($unitId, $current->weekStart, $user);

        $prevFacts = [];
        if (null !== $previous) {
            foreach ($previous->rows as $row) {
                $prevFacts[$row->metricId] = ['weekStart' => $previous->weekStart, 'weekEnd' => $previous->weekEnd, 'fact' => $row->week?->fact];
            }
        }

        return ['week' => $current, 'context' => [
            'unit' => $current->orgUnit->name,
            'week' => ['index' => $current->weekIndex, 'start' => $current->weekStart, 'end' => $current->weekEnd, 'isCurrent' => $current->isCurrentWeek],
            'monthStart' => $current->monthStart,
            'metrics' => array_map(fn (RnpRowDto $row): array => $this->row($row, $prevFacts[$row->metricId] ?? null), $current->rows),
            'actions' => $this->actionsSummary($actions),
        ]];
    }

    /**
     * @param array{weekStart: string, weekEnd: string, fact: ?string}|null $previous
     *
     * @return array<string, mixed>
     */
    public function row(RnpRowDto $row, ?array $previous = null): array
    {
        return array_filter([
            'metricId' => $row->metricId,
            'name' => $row->name,
            'unit' => $row->unit,
            'direction' => $row->direction,
            'source' => $row->isFormula ? 'formula' : $row->sourceType,
            'week' => $this->cell($row->week),
            'previousWeek' => $previous,
            'month' => $this->cell($row->month),
            'runRate' => null === $row->runRate ? null : ['value' => $row->runRate, 'completionPct' => $row->runRateCompletionPct, 'status' => $row->runRateColor],
            'pace' => null === $row->pace ? null : [
                'currentPerDay' => $row->pace->currentPerDay, 'requiredPerDay' => $row->pace->requiredPerDay,
                'remainingDays' => $row->pace->remainingDays, 'planReached' => $row->pace->planReached,
            ],
            'deviationNote' => null === $row->note ? null : ['reason' => $row->note->reason, 'action' => $row->note->action],
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function actionsSummary(ActionsWeekDto $actions): array
    {
        $openRoadmap = array_values(array_filter($actions->roadmap, static fn (RoadmapItemDto $item): bool => \in_array($item->status, ['planned', 'in_progress'], true)));

        return [
            'executionRateWeek' => ['done' => $actions->weekExecution->done, 'planned' => $actions->weekExecution->planned, 'pct' => $actions->weekExecution->pct],
            'executionRateMonth' => ['done' => $actions->monthExecution->done, 'planned' => $actions->monthExecution->planned, 'pct' => $actions->monthExecution->pct],
            'missedLast30Days' => array_map(static fn (MissedItemDto $m): array => [
                'title' => $m->title, 'kind' => $m->kind, 'due' => $m->due, 'owner' => $m->owner?->name,
            ], \array_slice($actions->missed, 0, 15)),
            'openRoadmap' => array_map(static fn (RoadmapItemDto $item): array => [
                'title' => $item->title, 'metric' => $item->metric->name, 'status' => $item->status, 'due' => $item->dueDate,
                'overdue' => $item->isOverdue, 'expectedEffect' => $item->expectedEffect,
            ], \array_slice($openRoadmap, 0, 15)),
        ];
    }

    /**
     * @return array<string, ?string>|null
     */
    private function cell(?RnpCellDto $cell): ?array
    {
        return null === $cell ? null : ['plan' => $cell->plan, 'fact' => $cell->fact, 'completionPct' => $cell->completionPct, 'status' => $cell->color];
    }
}
