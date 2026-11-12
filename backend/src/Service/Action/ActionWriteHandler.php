<?php

declare(strict_types=1);

namespace App\Service\Action;

use App\DTO\Request\BaseFunctionCheckRequest;
use App\DTO\Request\BaseFunctionRequest;
use App\DTO\Request\RoadmapEffectRequest;
use App\DTO\Request\RoadmapItemRequest;
use App\Entity\Action\BaseFunction;
use App\Entity\Action\BaseFunctionCheck;
use App\Entity\Action\RoadmapEvent;
use App\Entity\Action\RoadmapItem;
use App\Entity\Auth\User;
use App\Entity\Org\OrgUnit;
use App\Enum\Periodicity;
use App\Enum\RoadmapEffect;
use App\Enum\RoadmapEventType;
use App\Enum\RoadmapStatus;
use App\Repository\Metric\MetricRepository;
use App\Security\Access\AccessScopeResolver;
use App\Security\Voter\MetricVoter;
use App\Service\Org\OrgUnitFindHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Changes to base functions, their checks and the roadmap. Every roadmap change is written to
 * roadmap_event (append-only history).
 */
final readonly class ActionWriteHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private OrgUnitFindHandler $units,
        private MetricRepository $metrics,
        private AuthorizationCheckerInterface $authorization,
        private AccessScopeResolver $scopes,
        private ActionPeriods $periods,
        private ActionsWeekHandler $reader,
    ) {
    }

    public function createBaseFunction(int $orgUnitId, BaseFunctionRequest $request, User $user): int
    {
        $unit = $this->units->handle($orgUnitId);
        $this->assertCanManage($unit, $user);

        $function = new BaseFunction($unit, trim($request->title), Periodicity::from($request->periodicity));
        $this->apply($function, $request);

        $max = $this->em->createQueryBuilder()->select('MAX(f.sort)')->from(BaseFunction::class, 'f')
            ->where('f.orgUnit = :unit')->setParameter('unit', $unit)->getQuery()->getSingleScalarResult();
        $function->setSort((int) $max + 10);

        $this->em->persist($function);
        $this->em->flush();

        return (int) $function->getId();
    }

    public function updateBaseFunction(int $id, BaseFunctionRequest $request, User $user): void
    {
        $function = $this->visibleFunction($id);
        $this->assertCanManage($function->getOrgUnit(), $user);

        $function->setTitle(trim($request->title))->setPeriodicity(Periodicity::from($request->periodicity));
        $this->apply($function, $request);
        $this->em->flush();
    }

    public function check(int $id, BaseFunctionCheckRequest $request, User $user, ?\DateTimeImmutable $today = null): void
    {
        $function = $this->visibleFunction($id);
        if (!ActionPolicy::canCheck($function, $this->scopes->resolve($user), $user)) {
            throw new AccessDeniedHttpException('Нет прав отмечать выполнение этой функции.');
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->periodStart);
        $slot = false === $day ? null : $this->periods->slotStartingOn($function->getPeriodicity(), $day);
        if (null === $slot) {
            throw new UnprocessableEntityHttpException('periodStart не является началом периода этой функции.');
        }
        if ($slot->start > ($today ?? new \DateTimeImmutable())->setTime(0, 0)) {
            throw new UnprocessableEntityHttpException('Нельзя отметить период, который ещё не начался.');
        }

        $check = $this->em->getRepository(BaseFunctionCheck::class)->findOneBy(['baseFunction' => $function, 'periodStart' => $slot->start]);

        if (null === $request->done) {
            if (null !== $check) {
                $this->em->remove($check);
            }
        } elseif (null === $check) {
            $check = new BaseFunctionCheck($function, $slot->start, $request->done, $user);
            $check->setNote($this->text($request->note));
            $this->em->persist($check);
        } else {
            $check->mark($request->done, $user);
            $check->setNote($this->text($request->note));
        }

        $this->em->flush();
    }

    public function createRoadmapItem(int $metricId, RoadmapItemRequest $request, User $user): int
    {
        $metric = $this->metrics->find($metricId);
        if (null === $metric || !$this->authorization->isGranted(MetricVoter::VIEW, $metric)) {
            throw new NotFoundHttpException('Metric not found.');
        }
        $this->assertCanManage($metric->getOrgUnit(), $user);

        $item = new RoadmapItem($metric, trim($request->title));
        $item->setOwner($this->owner($metric->getOrgUnit(), $request->ownerUserId))
            ->setDueDate($this->date($request->dueDate))
            ->setExpectedEffect($this->text($request->expectedEffect));
        $this->em->persist($item);
        $this->em->persist(new RoadmapEvent($item, RoadmapEventType::Created, $user));

        $status = RoadmapStatus::from($request->status);
        if (RoadmapStatus::Planned !== $status) {
            $item->setStatus($status);
            $this->em->persist(new RoadmapEvent($item, RoadmapEventType::StatusChanged, $user, 'status', RoadmapStatus::Planned->value, $status->value));
        }

        $this->em->flush();

        return (int) $item->getId();
    }

    public function updateRoadmapItem(int $id, RoadmapItemRequest $request, User $user): void
    {
        $item = $this->visibleItem($id);
        $unit = $item->getMetric()->getOrgUnit();
        $scope = $this->scopes->resolve($user);
        $canManage = ActionPolicy::canManage($unit->getId(), $scope);

        $new = [
            'title' => trim($request->title),
            'owner' => $canManage ? $this->owner($unit, $request->ownerUserId) : $item->getOwner(),
            'due_date' => $this->date($request->dueDate),
            'expected_effect' => $this->text($request->expectedEffect),
        ];
        $old = ['title' => $item->getTitle(), 'owner' => $item->getOwner(), 'due_date' => $item->getDueDate(), 'expected_effect' => $item->getExpectedEffect()];
        $status = RoadmapStatus::from($request->status);

        $fieldsChanged = $new['title'] !== $old['title']
            || $new['owner']?->getId() !== $old['owner']?->getId()
            || $new['due_date']?->format('Y-m-d') !== $old['due_date']?->format('Y-m-d')
            || $new['expected_effect'] !== $old['expected_effect']
            || ($canManage ? false : $request->ownerUserId !== $item->getOwner()?->getId());

        if (!$canManage) {
            // The owner may only move the status; everything else stays with the managers.
            if (!ActionPolicy::canChangeStatus($item, $scope, $user) || $fieldsChanged) {
                throw new AccessDeniedHttpException('Нет прав изменять этот пункт дорожной карты.');
            }
        }

        $this->recordField($item, $user, 'title', $old['title'], $new['title']);
        $this->recordField($item, $user, 'owner', $old['owner']?->getName(), $new['owner']?->getName());
        $this->recordField($item, $user, 'due_date', $old['due_date']?->format('Y-m-d'), $new['due_date']?->format('Y-m-d'));
        $this->recordField($item, $user, 'expected_effect', $old['expected_effect'], $new['expected_effect']);
        if ($status !== $item->getStatus()) {
            $this->em->persist(new RoadmapEvent($item, RoadmapEventType::StatusChanged, $user, 'status', $item->getStatus()->value, $status->value));
        }

        $item->setTitle($new['title'])->setOwner($new['owner'])->setDueDate($new['due_date'])
            ->setExpectedEffect($new['expected_effect'])->setStatus($status);
        $this->em->flush();
    }

    public function assessEffect(int $id, RoadmapEffectRequest $request, User $user): void
    {
        $item = $this->visibleItem($id);
        $this->assertCanManage($item->getMetric()->getOrgUnit(), $user);

        if (RoadmapStatus::Done !== $item->getStatus()) {
            throw new UnprocessableEntityHttpException('Эффект оценивается после выполнения пункта.');
        }

        $this->em->persist(new RoadmapEvent($item, RoadmapEventType::EffectAssessed, $user, effect: RoadmapEffect::from($request->effect), note: $this->text($request->note)));
        $this->em->flush();
    }

    private function apply(BaseFunction $function, BaseFunctionRequest $request): void
    {
        $metric = null;
        if (null !== $request->metricId) {
            $metric = $this->metrics->find($request->metricId);
            if (null === $metric || $metric->getOrgUnit() !== $function->getOrgUnit()) {
                throw new UnprocessableEntityHttpException('Метрика должна принадлежать этому отделу.');
            }
        }

        $function->setMetric($metric)
            ->setOwnerUser($this->owner($function->getOrgUnit(), $request->ownerUserId))
            ->setDueText($this->text($request->dueText))
            ->setIsActive($request->isActive);
    }

    private function owner(OrgUnit $unit, ?int $userId): ?User
    {
        if (null === $userId) {
            return null;
        }

        foreach ($this->reader->people($unit) as $person) {
            if ($person->getId() === $userId) {
                return $person;
            }
        }

        throw new UnprocessableEntityHttpException('Исполнитель должен быть сотрудником этого отдела или его ветки.');
    }

    private function recordField(RoadmapItem $item, User $user, string $field, ?string $old, ?string $new): void
    {
        if ($old !== $new) {
            $this->em->persist(new RoadmapEvent($item, RoadmapEventType::FieldChanged, $user, $field, $old, $new));
        }
    }

    private function visibleFunction(int $id): BaseFunction
    {
        $function = $this->em->find(BaseFunction::class, $id) ?? throw new NotFoundHttpException('Base function not found.');
        $this->units->handle((int) $function->getOrgUnit()->getId());

        return $function;
    }

    private function visibleItem(int $id): RoadmapItem
    {
        $item = $this->em->find(RoadmapItem::class, $id);
        if (null === $item || !$this->authorization->isGranted(MetricVoter::VIEW, $item->getMetric())) {
            throw new NotFoundHttpException('Roadmap item not found.');
        }

        return $item;
    }

    private function assertCanManage(OrgUnit $unit, User $user): void
    {
        if (!ActionPolicy::canManage($unit->getId(), $this->scopes->resolve($user))) {
            throw new AccessDeniedHttpException('Изменять действия отдела может руководитель или администратор.');
        }
    }

    private function date(?string $value): ?\DateTimeImmutable
    {
        return null === $value || '' === $value ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null);
    }

    private function text(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
