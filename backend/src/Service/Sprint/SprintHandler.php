<?php

declare(strict_types=1);

namespace App\Service\Sprint;

use App\DTO\Action\MetricRefDto;
use App\DTO\Request\RecurringTaskRequest;
use App\DTO\Request\SprintTaskRequest;
use App\DTO\Sprint\RecurringTaskDto;
use App\DTO\Sprint\SprintTaskDto;
use App\DTO\Sprint\SprintWeekDto;
use App\Entity\Action\RecurringTask;
use App\Entity\Action\SprintTask;
use App\Entity\Auth\User;
use App\Entity\Metric\Metric;
use App\Entity\Org\OrgUnit;
use App\Enum\Periodicity;
use App\Enum\SprintPriority;
use App\Repository\Metric\MetricRepository;
use App\Security\Access\AccessScopeResolver;
use App\Service\Org\OrgUnitFindHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Weekly sprints of an org unit (Monday–Sunday weeks) and its recurring tasks.
 *
 *  - edit tasks: heads of the branch, members of the unit, admins; viewers read only;
 *  - manage recurring tasks: heads and admins;
 *  - a task is done at 100 % progress;
 *  - carry over copies unfinished tasks into the next week once (carried_from);
 *  - recurring tasks are pulled into a week on request, never twice under the same title.
 */
final readonly class SprintHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private OrgUnitFindHandler $units,
        private MetricRepository $metrics,
        private AccessScopeResolver $scopes,
    ) {
    }

    public static function monday(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->setTime(0, 0)->modify('monday this week');
    }

    public function week(int $orgUnitId, ?string $week, User $user, ?\DateTimeImmutable $today = null): SprintWeekDto
    {
        $unit = $this->units->handle($orgUnitId);
        $monday = self::monday($this->date($week) ?? $today ?? new \DateTimeImmutable());
        $scope = $this->scopes->resolve($user);

        $tasks = $this->tasksOf($unit, $monday);
        $carried = $this->carriedIds($tasks);
        $carryable = array_filter($tasks, static fn (SprintTask $t): bool => $t->getProgressPct() < 100 && !\in_array($t->getId(), $carried, true));

        /** @var list<RecurringTask> $recurring */
        $recurring = $this->em->getRepository(RecurringTask::class)->findBy(['orgUnit' => $unit], ['isActive' => 'DESC', 'id' => 'ASC']);

        return new SprintWeekDto(
            (int) $unit->getId(),
            $monday->format('Y-m-d'),
            $monday->modify('+6 days')->format('Y-m-d'),
            $monday->modify('-7 days')->format('Y-m-d'),
            $monday->modify('+7 days')->format('Y-m-d'),
            $scope->canEnterFacts($unit->getId()),
            $scope->canSetPlans($unit->getId()),
            \count(array_filter($tasks, static fn (SprintTask $t): bool => 100 === $t->getProgressPct())),
            [] === $tasks ? null : (int) round(array_sum(array_map(static fn (SprintTask $t): int => $t->getProgressPct(), $tasks)) / \count($tasks)),
            \count($carryable),
            array_map(SprintTaskDto::fromEntity(...), $tasks),
            array_map(RecurringTaskDto::fromEntity(...), $recurring),
            array_map(static fn (Metric $m): MetricRefDto => new MetricRefDto((int) $m->getId(), $m->getName()), $this->metrics->findActiveByOrgUnits([(int) $unit->getId()])),
        );
    }

    public function create(int $orgUnitId, SprintTaskRequest $request, User $user): int
    {
        $unit = $this->units->handle($orgUnitId);
        $this->assertCanEdit($unit, $user);

        $week = $this->date($request->weekStart) ?? throw new UnprocessableEntityHttpException('Укажите неделю спринта (weekStart).');
        $task = new SprintTask($unit, self::monday($week), trim($request->title));
        $this->apply($task, $request);
        $this->em->persist($task);
        $this->em->flush();

        return (int) $task->getId();
    }

    public function update(int $id, SprintTaskRequest $request, User $user): void
    {
        $task = $this->visibleTask($id);
        $this->assertCanEdit($task->getOrgUnit(), $user);

        $task->setTitle(trim($request->title));
        $this->apply($task, $request);
        $this->em->flush();
    }

    public function delete(int $id, User $user): void
    {
        $task = $this->visibleTask($id);
        $this->assertCanEdit($task->getOrgUnit(), $user);

        $this->em->remove($task);
        $this->em->flush();
    }

    /**
     * Copies the unfinished tasks of a week into the next one. Returns the number of copies.
     */
    public function carryOver(int $orgUnitId, string $week, User $user): int
    {
        $unit = $this->units->handle($orgUnitId);
        $this->assertCanEdit($unit, $user);

        $monday = self::monday($this->date($week) ?? throw new BadRequestHttpException('Parameter "week" is required.'));
        $tasks = $this->tasksOf($unit, $monday);
        $carried = $this->carriedIds($tasks);
        $count = 0;

        foreach ($tasks as $task) {
            if ($task->getProgressPct() >= 100 || \in_array($task->getId(), $carried, true)) {
                continue;
            }

            $copy = new SprintTask($unit, $monday->modify('+7 days'), $task->getTitle());
            $copy->setTag($task->getTag())->setPriority($task->getPriority())->setMetric($task->getMetric())
                ->setProgressPct($task->getProgressPct())->setLink($task->getLink())
                ->setResultNote($task->getResultNote())->setNextStep($task->getNextStep())->setCarriedFrom($task);
            $this->em->persist($copy);
            ++$count;
        }

        $this->em->flush();

        return $count;
    }

    /**
     * Adds the active recurring tasks due in the week. Returns the number of added tasks.
     */
    public function pullRecurring(int $orgUnitId, string $week, User $user): int
    {
        $unit = $this->units->handle($orgUnitId);
        $this->assertCanEdit($unit, $user);

        $monday = self::monday($this->date($week) ?? throw new BadRequestHttpException('Parameter "week" is required.'));
        $existing = array_map(static fn (SprintTask $t): string => mb_strtolower($t->getTitle()), $this->tasksOf($unit, $monday));

        /** @var list<RecurringTask> $recurring */
        $recurring = $this->em->getRepository(RecurringTask::class)->findBy(['orgUnit' => $unit, 'isActive' => true], ['id' => 'ASC']);
        $count = 0;

        foreach ($recurring as $template) {
            if (!$this->dueInWeek($template->getPeriodicity(), $monday) || \in_array(mb_strtolower($template->getTitle()), $existing, true)) {
                continue;
            }

            $task = new SprintTask($unit, $monday, $template->getTitle());
            $task->setTag('регулярная');
            $this->em->persist($task);
            $existing[] = mb_strtolower($template->getTitle());
            ++$count;
        }

        $this->em->flush();

        return $count;
    }

    public function createRecurring(int $orgUnitId, RecurringTaskRequest $request, User $user): int
    {
        $unit = $this->units->handle($orgUnitId);
        $this->assertCanManageRecurring($unit, $user);

        $task = new RecurringTask($unit, trim($request->title), Periodicity::from($request->periodicity));
        $task->setIsActive($request->isActive);
        $this->em->persist($task);
        $this->em->flush();

        return (int) $task->getId();
    }

    public function updateRecurring(int $id, RecurringTaskRequest $request, User $user): void
    {
        $task = $this->em->find(RecurringTask::class, $id) ?? throw new NotFoundHttpException('Recurring task not found.');
        $this->units->handle((int) $task->getOrgUnit()->getId());
        $this->assertCanManageRecurring($task->getOrgUnit(), $user);

        $task->setTitle(trim($request->title))->setPeriodicity(Periodicity::from($request->periodicity))->setIsActive($request->isActive);
        $this->em->flush();
    }

    /**
     * Sprint tasks of a unit whose week (Monday–Sunday) ends within [from, to] — Execution rate input.
     *
     * @return list<SprintTask>
     */
    public function tasksEndingBetween(OrgUnit $unit, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<SprintTask> $tasks */
        $tasks = $this->em->createQueryBuilder()
            ->select('t')->from(SprintTask::class, 't')
            ->where('t.orgUnit = :unit')
            ->andWhere('t.weekStart BETWEEN :from AND :to')
            ->setParameter('unit', $unit)
            ->setParameter('from', $from->modify('-6 days')->format('Y-m-d'))
            ->setParameter('to', $to->modify('-6 days')->format('Y-m-d'))
            ->getQuery()->getResult();

        return $tasks;
    }

    private function dueInWeek(Periodicity $periodicity, \DateTimeImmutable $monday): bool
    {
        $firstMondayOf = static fn (\DateTimeImmutable $start): \DateTimeImmutable => '1' === $start->format('N') ? $start : $start->modify('next monday');

        return match ($periodicity) {
            Periodicity::Daily, Periodicity::Weekly => true,
            Periodicity::Monthly => $firstMondayOf($monday->modify('first day of this month'))->format('Y-m-d') === $monday->format('Y-m-d'),
            Periodicity::Quarterly => (function () use ($monday, $firstMondayOf): bool {
                $quarterMonth = intdiv((int) $monday->format('n') - 1, 3) * 3 + 1;
                $start = new \DateTimeImmutable(\sprintf('%s-%02d-01', $monday->format('Y'), $quarterMonth));

                return $firstMondayOf($start)->format('Y-m-d') === $monday->format('Y-m-d');
            })(),
        };
    }

    private function apply(SprintTask $task, SprintTaskRequest $request): void
    {
        $metric = null;
        if (null !== $request->metricId) {
            $metric = $this->metrics->find($request->metricId);
            if (null === $metric || $metric->getOrgUnit() !== $task->getOrgUnit()) {
                throw new UnprocessableEntityHttpException('Метрика должна принадлежать этому отделу.');
            }
        }

        $task->setPriority(SprintPriority::from($request->priority))
            ->setTag($this->text($request->tag))
            ->setMetric($metric)
            ->setProgressPct($request->progressPct)
            ->setResultNote($this->text($request->resultNote))
            ->setLink($this->text($request->link))
            ->setNextStep($this->text($request->nextStep));
    }

    /**
     * @return list<SprintTask> by priority (P1 first), then creation
     */
    private function tasksOf(OrgUnit $unit, \DateTimeImmutable $monday): array
    {
        /** @var list<SprintTask> $tasks */
        $tasks = $this->em->getRepository(SprintTask::class)->findBy(['orgUnit' => $unit, 'weekStart' => $monday], ['priority' => 'ASC', 'id' => 'ASC']);

        return $tasks;
    }

    /**
     * @param list<SprintTask> $tasks
     *
     * @return list<int> ids among $tasks that already have a carried-over copy
     */
    private function carriedIds(array $tasks): array
    {
        if ([] === $tasks) {
            return [];
        }

        /** @var list<int|string> $ids */
        $ids = $this->em->createQueryBuilder()
            ->select('IDENTITY(t.carriedFrom)')->from(SprintTask::class, 't')
            ->where('t.carriedFrom IN (:tasks)')->setParameter('tasks', $tasks)
            ->getQuery()->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }

    private function visibleTask(int $id): SprintTask
    {
        $task = $this->em->find(SprintTask::class, $id) ?? throw new NotFoundHttpException('Sprint task not found.');
        $this->units->handle((int) $task->getOrgUnit()->getId());

        return $task;
    }

    private function assertCanEdit(OrgUnit $unit, User $user): void
    {
        if (!$this->scopes->resolve($user)->canEnterFacts($unit->getId())) {
            throw new AccessDeniedHttpException('Спринт отдела ведут руководитель и сотрудники отдела.');
        }
    }

    private function assertCanManageRecurring(OrgUnit $unit, User $user): void
    {
        if (!$this->scopes->resolve($user)->canSetPlans($unit->getId())) {
            throw new AccessDeniedHttpException('Регулярные задачи настраивает руководитель отдела.');
        }
    }

    private function date(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException('Dates must be Y-m-d.');
        }

        return $date;
    }

    private function text(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
