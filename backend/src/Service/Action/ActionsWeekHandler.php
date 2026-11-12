<?php

declare(strict_types=1);

namespace App\Service\Action;

use App\DTO\Action\ActionSlotDto;
use App\DTO\Action\ActionsWeekDto;
use App\DTO\Action\BaseFunctionDto;
use App\DTO\Action\ExecutionRateDto;
use App\DTO\Action\MetricRefDto;
use App\DTO\Action\MissedItemDto;
use App\DTO\Action\RoadmapItemDto;
use App\DTO\UserRefDto;
use App\Entity\Action\BaseFunction;
use App\Entity\Action\BaseFunctionCheck;
use App\Entity\Action\RoadmapEvent;
use App\Entity\Action\RoadmapItem;
use App\Entity\Auth\User;
use App\Entity\Metric\Metric;
use App\Entity\Org\OrgUnit;
use App\Enum\RoadmapStatus;
use App\Repository\Metric\MetricRepository;
use App\Repository\Org\OrgMembershipRepository;
use App\Repository\Org\OrgUnitPathRepository;
use App\Security\Access\AccessScopeResolver;
use App\Service\Org\OrgUnitFindHandler;
use App\Service\Period\PeriodCalendar;
use App\Service\Sprint\SprintHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * "Actions" screen of an org unit for one week: base functions with their check slots,
 * the roadmap with history, Execution rate of the week and of its month, and what was missed
 * during the last 30 days.
 *
 * Execution rate = done / planned. A slot or roadmap item is planned when its due date falls in
 * the period and is already reached (or it is done early) — the running week is not "failed" upfront.
 */
final readonly class ActionsWeekHandler
{
    private const int MISSED_WINDOW_DAYS = 30;

    public function __construct(
        private OrgUnitFindHandler $units,
        private EntityManagerInterface $em,
        private MetricRepository $metrics,
        private OrgMembershipRepository $memberships,
        private OrgUnitPathRepository $paths,
        private AccessScopeResolver $scopes,
        private ActionPeriods $periods,
        private SprintHandler $sprints,
        private PeriodCalendar $calendar,
    ) {
    }

    public function handle(int $orgUnitId, ?string $week, User $user, ?\DateTimeImmutable $today = null): ActionsWeekDto
    {
        $unit = $this->units->handle($orgUnitId);
        $today = ($today ?? new \DateTimeImmutable())->setTime(0, 0);
        $segment = $this->calendar->weekSegmentFor($this->parseWeek($week) ?? $today);
        $monthStart = $segment->start->modify('first day of this month');
        $monthEnd = $monthStart->modify('last day of this month');
        $scope = $this->scopes->resolve($user);
        $canManage = ActionPolicy::canManage($unit->getId(), $scope);

        /** @var list<BaseFunction> $functions */
        $functions = $this->em->getRepository(BaseFunction::class)->findBy(['orgUnit' => $unit, 'isActive' => true], ['sort' => 'ASC', 'id' => 'ASC']);
        $missedFrom = $today->modify(\sprintf('-%d days', self::MISSED_WINDOW_DAYS));
        $checks = $this->checks($functions, min($missedFrom, $monthStart)->modify('-3 months'), max($monthEnd, $today));

        $items = $this->roadmapItems($unit);
        $events = $this->events($items);

        $baseFunctions = [];
        foreach ($functions as $function) {
            $slots = [];
            foreach ($this->periods->displaySlots($function->getPeriodicity(), $segment) as $slot) {
                $slots[] = $this->slotDto($slot, $checks[(int) $function->getId()][$slot->key()] ?? null, $today);
            }

            $metric = $function->getMetric();
            $baseFunctions[] = new BaseFunctionDto(
                (int) $function->getId(),
                $function->getTitle(),
                $function->getPeriodicity()->value,
                $function->getDueText(),
                null === $metric ? null : new MetricRefDto((int) $metric->getId(), $metric->getName()),
                UserRefDto::fromNullable($function->getOwnerUser()),
                $function->isActive(),
                ActionPolicy::canCheck($function, $scope, $user),
                $slots,
            );
        }

        $roadmap = array_map(
            fn (RoadmapItem $item): RoadmapItemDto => RoadmapItemDto::fromEntity($item, $events[(int) $item->getId()] ?? [], $today, $canManage, ActionPolicy::canChangeStatus($item, $scope, $user)),
            $items,
        );

        return new ActionsWeekDto(
            (int) $unit->getId(),
            $segment->start->format('Y-m-d'),
            $segment->end->format('Y-m-d'),
            $monthStart->format('Y-m-d'),
            $this->calendar->weekSegmentFor($segment->start->modify('-1 day'))->start->format('Y-m-d'),
            $segment->end->modify('+1 day')->format('Y-m-d'),
            $canManage,
            $this->rate($functions, $checks, $items, $unit, $segment->start, $segment->end, $today),
            $this->rate($functions, $checks, $items, $unit, $monthStart, $monthEnd, $today),
            $baseFunctions,
            $roadmap,
            $this->missed($functions, $checks, $items, $missedFrom, $today),
            array_map(
                static fn (Metric $m): MetricRefDto => new MetricRefDto((int) $m->getId(), $m->getName()),
                $this->metrics->findActiveByOrgUnits([(int) $unit->getId()]),
            ),
            array_map(UserRefDto::fromEntity(...), $this->people($unit)),
        );
    }

    /**
     * Members of the unit's branch and of its ancestors: everyone who can own an action here.
     *
     * @return list<User>
     */
    public function people(OrgUnit $unit): array
    {
        $id = (int) $unit->getId();

        return $this->memberships->findActiveUsersInUnits([...$this->paths->findBranchIds([$id]), ...$this->paths->findAncestorIds($id)]);
    }

    /**
     * @param list<BaseFunction>                           $functions
     * @param array<int, array<string, BaseFunctionCheck>> $checks
     * @param list<RoadmapItem>                            $items
     */
    private function rate(array $functions, array $checks, array $items, OrgUnit $unit, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): ExecutionRateDto
    {
        [$bfPlanned, $bfDone] = [0, 0];
        foreach ($functions as $function) {
            foreach ($this->periods->dueSlots($function->getPeriodicity(), $from, $to) as $slot) {
                $done = true === ($checks[(int) $function->getId()][$slot->key()] ?? null)?->isDone();
                if ($slot->due <= $today || $done) {
                    ++$bfPlanned;
                    $bfDone += (int) $done;
                }
            }
        }

        [$rmPlanned, $rmDone] = [0, 0];
        foreach ($items as $item) {
            $due = $item->getDueDate();
            if (null === $due || RoadmapStatus::Cancelled === $item->getStatus() || $due < $from || $due > $to) {
                continue;
            }
            $done = RoadmapStatus::Done === $item->getStatus();
            if ($due <= $today || $done) {
                ++$rmPlanned;
                $rmDone += (int) $done;
            }
        }

        // Sprint tasks count in the period where their week (Monday–Sunday) ends; done = 100 %.
        [$spPlanned, $spDone] = [0, 0];
        foreach ($this->sprints->tasksEndingBetween($unit, $from, $to) as $task) {
            $done = 100 === $task->getProgressPct();
            if ($task->getWeekStart()->modify('+6 days') <= $today || $done) {
                ++$spPlanned;
                $spDone += (int) $done;
            }
        }

        $planned = $bfPlanned + $rmPlanned + $spPlanned;
        $done = $bfDone + $rmDone + $spDone;

        return new ExecutionRateDto(
            $planned,
            $done,
            0 === $planned ? null : rtrim(rtrim(number_format($done * 100 / $planned, 1, '.', ''), '0'), '.'),
            $bfPlanned,
            $bfDone,
            $rmPlanned,
            $rmDone,
            $spPlanned,
            $spDone,
        );
    }

    /**
     * @param list<BaseFunction>                           $functions
     * @param array<int, array<string, BaseFunctionCheck>> $checks
     * @param list<RoadmapItem>                            $items
     *
     * @return list<MissedItemDto>
     */
    private function missed(array $functions, array $checks, array $items, \DateTimeImmutable $from, \DateTimeImmutable $today): array
    {
        $missed = [];
        $yesterday = $today->modify('-1 day');

        foreach ($functions as $function) {
            foreach ($this->periods->dueSlots($function->getPeriodicity(), $from, $yesterday) as $slot) {
                if (true !== ($checks[(int) $function->getId()][$slot->key()] ?? null)?->isDone()) {
                    $missed[] = new MissedItemDto('base_function', (int) $function->getId(), $function->getTitle(), $slot->key(), $slot->due->format('Y-m-d'), UserRefDto::fromNullable($function->getOwnerUser()));
                }
            }
        }

        foreach ($items as $item) {
            $due = $item->getDueDate();
            if (null !== $due && $due < $today && \in_array($item->getStatus(), [RoadmapStatus::Planned, RoadmapStatus::InProgress], true)) {
                $missed[] = new MissedItemDto('roadmap', (int) $item->getId(), $item->getTitle(), $due->format('Y-m-d'), $due->format('Y-m-d'), UserRefDto::fromNullable($item->getOwner()));
            }
        }

        usort($missed, static fn (MissedItemDto $a, MissedItemDto $b): int => [$b->due, $a->title] <=> [$a->due, $b->title]);

        return $missed;
    }

    private function slotDto(ActionSlot $slot, ?BaseFunctionCheck $check, \DateTimeImmutable $today): ActionSlotDto
    {
        $state = match (true) {
            true === $check?->isDone() => 'done',
            false === $check?->isDone() => 'not_done',
            $slot->start > $today => 'future',
            $slot->due < $today => 'missed',
            default => 'pending',
        };

        return new ActionSlotDto(
            $slot->key(),
            $slot->due->format('Y-m-d'),
            $state,
            $check?->isDone(),
            $check?->getNote(),
            UserRefDto::fromNullable($check?->getCheckedBy()),
            $check?->getCheckedAt()->format(\DATE_ATOM),
        );
    }

    /**
     * @param list<BaseFunction> $functions
     *
     * @return array<int, array<string, BaseFunctionCheck>> function id => period start => check
     */
    private function checks(array $functions, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ([] === $functions) {
            return [];
        }

        /** @var list<BaseFunctionCheck> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('c')->from(BaseFunctionCheck::class, 'c')
            ->where('c.baseFunction IN (:functions)')
            ->andWhere('c.periodStart BETWEEN :from AND :to')
            ->setParameter('functions', $functions)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->getQuery()->getResult();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row->getBaseFunction()->getId()][$row->getPeriodStart()->format('Y-m-d')] = $row;
        }

        return $indexed;
    }

    /**
     * Open items first (in progress, planned), then done and cancelled; by due date inside.
     *
     * @return list<RoadmapItem>
     */
    private function roadmapItems(OrgUnit $unit): array
    {
        /** @var list<RoadmapItem> $items */
        $items = $this->em->createQueryBuilder()
            ->select('i', 'm')->from(RoadmapItem::class, 'i')
            ->join('i.metric', 'm')
            ->where('m.orgUnit = :unit')
            ->setParameter('unit', $unit)
            ->getQuery()->getResult();

        $rank = [RoadmapStatus::InProgress->value => 0, RoadmapStatus::Planned->value => 1, RoadmapStatus::Done->value => 2, RoadmapStatus::Cancelled->value => 3];
        usort($items, static fn (RoadmapItem $a, RoadmapItem $b): int => [$rank[$a->getStatus()->value], $a->getDueDate()?->format('Y-m-d') ?? '9999', $a->getId()]
            <=> [$rank[$b->getStatus()->value], $b->getDueDate()?->format('Y-m-d') ?? '9999', $b->getId()]);

        return $items;
    }

    /**
     * @param list<RoadmapItem> $items
     *
     * @return array<int, list<RoadmapEvent>> item id => events, newest first
     */
    private function events(array $items): array
    {
        if ([] === $items) {
            return [];
        }

        /** @var list<RoadmapEvent> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('e')->from(RoadmapEvent::class, 'e')
            ->where('e.roadmapItem IN (:items)')
            ->setParameter('items', $items)
            ->orderBy('e.createdAt', \SortDirection::Descending)
            ->addOrderBy('e.id', \SortDirection::Descending)
            ->getQuery()->getResult();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row->getRoadmapItem()->getId()][] = $row;
        }

        return $indexed;
    }

    private function parseWeek(?string $week): ?\DateTimeImmutable
    {
        if (null === $week || '' === $week) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $week);
        if (false === $date || $date->format('Y-m-d') !== $week) {
            throw new BadRequestHttpException('Parameter "week" must be a date in Y-m-d format.');
        }

        return $date;
    }
}
