<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\DTO\Action\MissedItemDto;
use App\DTO\ErrorLog\ErrorEntryDto;
use App\DTO\NotificationDto;
use App\DTO\Rnp\RnpRowDto;
use App\Entity\Auth\User;
use App\Entity\Org\OrgUnit;
use App\Enum\OrgRole;
use App\Repository\Org\OrgMembershipRepository;
use App\Service\Action\ActionsWeekHandler;
use App\Service\ErrorLog\ErrorLogHandler;
use App\Service\Period\PeriodCalendar;
use App\Service\Rnp\RnpWeekHandler;
use App\Service\Sync\DataStatusHandler;

/**
 * Alerts of the notification bell, computed from the current state through the regular handlers (so with the
 * user's own access rights and personal metric view):
 *  - heads of a unit: metrics red in the last finished week, run rate behind the month plan, missed base
 *    functions and roadmap items, new error log entries reported by others;
 *  - heads and members: manual weekly facts of the last finished week not entered yet; members also get the
 *    missed actions they own;
 *  - ROLE_ADMIN: a stale or failing data source.
 * Units come from the user's direct head / member memberships: the head of the company gets its own (rolled-up)
 * metrics, not every department's.
 */
final readonly class AlertCollector
{
    /** Run rate is too noisy during the first days of a month. */
    private const int RUN_RATE_FROM_DAY = 7;

    private const int ERRORS_NEW_DAYS = 7;

    private const int METRIC_NAMES = 3;

    public function __construct(
        private OrgMembershipRepository $memberships,
        private RnpWeekHandler $rnp,
        private ActionsWeekHandler $actions,
        private ErrorLogHandler $errors,
        private DataStatusHandler $dataStatus,
        private PeriodCalendar $calendar,
    ) {
    }

    /**
     * @return list<NotificationDto>
     */
    public function collect(User $user, \DateTimeImmutable $now): array
    {
        $alerts = \in_array('ROLE_ADMIN', $user->getRoles(), true) ? $this->dataAlerts($now) : [];

        /** @var array<int, array{OrgUnit, bool}> $units unit id => [unit, is head] */
        $units = [];
        foreach ($this->memberships->findForUser($user) as $membership) {
            $role = $membership->getRole();
            if (OrgRole::Head !== $role && OrgRole::Member !== $role) {
                continue;
            }
            $unit = $membership->getOrgUnit();
            $id = (int) $unit->getId();
            // A head membership wins over a member one in the same unit.
            $units[$id] = [$unit, ($units[$id][1] ?? false) || OrgRole::Head === $role];
        }

        foreach ($units as [$unit, $isHead]) {
            array_push($alerts, ...$this->unitAlerts($unit, $isHead, $user, $now->setTime(0, 0)));
        }

        return $alerts;
    }

    /**
     * @return list<NotificationDto>
     */
    private function unitAlerts(OrgUnit $unit, bool $isHead, User $user, \DateTimeImmutable $today): array
    {
        $id = (int) $unit->getId();
        $name = $unit->getName();
        $alerts = [];

        // The last finished week segment is the one before today's.
        $current = $this->calendar->weekSegmentFor($today);
        $last = $this->calendar->weekSegmentFor($current->start->modify('-1 day'));
        $lastWeek = $this->rnp->handle($id, $last->start->format('Y-m-d'), $user, $today);
        $weekKey = $last->start->format('Y-m-d');
        $weekOver = $current->start->format(\DATE_ATOM);

        if ($isHead) {
            $red = array_filter($lastWeek->rows, static fn (RnpRowDto $row): bool => 'red' === $row->week?->color);
            if ([] !== $red) {
                $alerts[] = $this->metricAlert('metric_red', $id, $name, $weekOver, $red, $weekKey);
            }

            if ((int) $today->format('j') >= self::RUN_RATE_FROM_DAY) {
                $thisWeek = $this->rnp->handle($id, $today->format('Y-m-d'), $user, $today);
                $behind = array_filter($thisWeek->rows, static fn (RnpRowDto $row): bool => 'red' === $row->runRateColor);
                if ([] !== $behind) {
                    $alerts[] = $this->metricAlert('run_rate_behind', $id, $name, $current->start->format(\DATE_ATOM), $behind, null);
                }
            }
        }

        $missingFacts = array_filter(
            $lastWeek->rows,
            static fn (RnpRowDto $row): bool => $row->isManual && !$row->isFormula && $row->canEnterFact && null !== $row->week && null === $row->week->fact,
        );
        if ([] !== $missingFacts) {
            $alerts[] = $this->metricAlert('facts_missing', $id, $name, $weekOver, $missingFacts, $weekKey);
        }

        $missed = $this->actions->handle($id, null, $user, $today)->missed;
        if (!$isHead) {
            $missed = array_filter($missed, static fn (MissedItemDto $item): bool => null !== $item->owner && $item->owner->id === $user->getId());
        }
        if ([] !== $missed) {
            $newestDue = max(array_map(static fn (MissedItemDto $item): string => $item->due, $missed));
            $alerts[] = new NotificationDto('actions_missed', $id, $name, (new \DateTimeImmutable($newestDue))->modify('+1 day')->format(\DATE_ATOM), count: \count($missed));
        }

        if ($isHead) {
            $since = $today->modify(\sprintf('-%d days', self::ERRORS_NEW_DAYS));
            $fresh = array_filter(
                $this->errors->list($id, $since->modify('-1 month')->format('Y-m-d'), $today->format('Y-m-d'), $user, $today)->entries,
                static fn (ErrorEntryDto $entry): bool => (null === $entry->reporter || $entry->reporter->id !== $user->getId())
                    && new \DateTimeImmutable($entry->createdAt) >= $since,
            );
            if ([] !== $fresh) {
                $newest = max(array_map(static fn (ErrorEntryDto $entry): string => $entry->createdAt, $fresh));
                $alerts[] = new NotificationDto('errors_new', $id, $name, $newest, count: \count($fresh));
            }
        }

        return $alerts;
    }

    /**
     * @param array<RnpRowDto> $rows
     */
    private function metricAlert(string $type, int $unitId, string $unitName, string $at, array $rows, ?string $week): NotificationDto
    {
        $rows = array_values($rows);
        $first = \array_slice($rows, 0, self::METRIC_NAMES);

        return new NotificationDto(
            $type,
            $unitId,
            $unitName,
            $at,
            count: \count($rows),
            metrics: array_map(static fn (RnpRowDto $row): string => $row->name, $first),
            week: $week,
            metricIds: array_map(static fn (RnpRowDto $row): int => $row->metricId, $first),
        );
    }

    /**
     * @return list<NotificationDto>
     */
    private function dataAlerts(\DateTimeImmutable $now): array
    {
        $alerts = [];
        foreach ($this->dataStatus->handle(false, $now)->sources as $source) {
            if ($source->configured && ($source->stale || $source->failing)) {
                $alerts[] = new NotificationDto(
                    'data_stale',
                    null,
                    null,
                    $source->dataAsOf ?? $now->format(\DATE_ATOM),
                    status: $source->stale ? 'stale' : 'failing',
                    source: $source->source,
                );
            }
        }

        return $alerts;
    }
}
