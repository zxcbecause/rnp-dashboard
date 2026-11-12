<?php

declare(strict_types=1);

namespace App\Service\ErrorLog;

use App\DTO\ErrorLog\ErrorEntryDto;
use App\DTO\ErrorLog\ErrorLogDto;
use App\DTO\Request\ErrorEntryRequest;
use App\DTO\Rnp\RnpCellDto;
use App\DTO\UserRefDto;
use App\Entity\Auth\User;
use App\Entity\Journal\ErrorEntry;
use App\Entity\Org\OrgUnit;
use App\Enum\ErrorImpactType;
use App\Enum\ErrorStatus;
use App\Enum\MetricUnit;
use App\Repository\Org\OrgUnitPathRepository;
use App\Security\Access\AccessScope;
use App\Security\Access\AccessScopeResolver;
use App\Service\Action\ActionsWeekHandler;
use App\Service\Metric\MetricValueNormalizer;
use App\Service\Org\OrgUnitFindHandler;
use BcMath\Number;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Error log of an org unit: significant mistakes with consequences. The list covers the unit and every
 * descendant the user can see (a head reads the whole branch, a member only the own unit).
 *  - report: heads, members of the unit, admins;
 *  - edit: heads / admins, the reporter and the owner.
 */
final readonly class ErrorLogHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private OrgUnitFindHandler $units,
        private AccessScopeResolver $scopes,
        private ActionsWeekHandler $people,
        private OrgUnitPathRepository $paths,
    ) {
    }

    public function list(int $orgUnitId, ?string $from, ?string $to, User $user, ?\DateTimeImmutable $today = null): ErrorLogDto
    {
        $unit = $this->units->handle($orgUnitId);
        $today = ($today ?? new \DateTimeImmutable())->setTime(0, 0);
        $end = $this->date($to) ?? $today;
        $start = $this->date($from) ?? $end->modify('first day of -2 months');
        if ($start > $end) {
            throw new BadRequestHttpException('"from" must not be after "to".');
        }
        $scope = $this->scopes->resolve($user);
        $unitIds = array_values(array_filter(
            $this->paths->findBranchIds([(int) $unit->getId()]),
            static fn (int $id): bool => $scope->canSeeUnit($id),
        ));

        /** @var list<ErrorEntry> $entries */
        $entries = $this->em->createQueryBuilder()
            ->select('e')->from(ErrorEntry::class, 'e')
            ->where('IDENTITY(e.orgUnit) IN (:units)')
            ->andWhere('e.occurredOn BETWEEN :from AND :to')
            ->setParameter('units', $unitIds)
            ->setParameter('from', $start->format('Y-m-d'))
            ->setParameter('to', $end->format('Y-m-d'))
            ->orderBy('e.occurredOn', \SortDirection::Descending)
            ->addOrderBy('e.id', \SortDirection::Descending)
            ->getQuery()->getResult();

        $byImpact = array_fill_keys(array_map(static fn (ErrorImpactType $t): string => $t->value, ErrorImpactType::cases()), 0);
        $money = new Number('0');
        $open = 0;
        foreach ($entries as $entry) {
            ++$byImpact[$entry->getImpactType()->value];
            $money += $entry->getImpactAmount() ?? new Number('0');
            $open += (int) (ErrorStatus::Resolved !== $entry->getStatus());
        }

        return new ErrorLogDto(
            (int) $unit->getId(),
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
            $scope->canEnterFacts($unit->getId()),
            \count($entries),
            $open,
            $byImpact,
            (string) RnpCellDto::decimal($money, 2),
            array_map(fn (ErrorEntry $e): ErrorEntryDto => ErrorEntryDto::fromEntity($e, $this->canEdit($e, $scope, $user)), $entries),
            array_map(UserRefDto::fromEntity(...), $this->people->people($unit)),
        );
    }

    public function create(int $orgUnitId, ErrorEntryRequest $request, User $user, ?\DateTimeImmutable $today = null): int
    {
        $unit = $this->units->handle($orgUnitId);
        if (!$this->scopes->resolve($user)->canEnterFacts($unit->getId())) {
            throw new AccessDeniedHttpException('Записывать ошибки могут руководитель и сотрудники отдела.');
        }

        $entry = new ErrorEntry($unit, $this->occurredOn($request, $today), trim($request->description), ErrorImpactType::from($request->impactType), $user);
        $this->apply($entry, $unit, $request);
        $this->em->persist($entry);
        $this->em->flush();

        return (int) $entry->getId();
    }

    public function update(int $id, ErrorEntryRequest $request, User $user, ?\DateTimeImmutable $today = null): void
    {
        $entry = $this->em->find(ErrorEntry::class, $id) ?? throw new NotFoundHttpException('Error entry not found.');
        $unit = $this->units->handle((int) $entry->getOrgUnit()->getId());
        if (!$this->canEdit($entry, $this->scopes->resolve($user), $user)) {
            throw new AccessDeniedHttpException('Изменять запись могут руководитель, автор и ответственный.');
        }

        $entry->setOccurredOn($this->occurredOn($request, $today))
            ->setDescription(trim($request->description))
            ->setImpactType(ErrorImpactType::from($request->impactType));
        $this->apply($entry, $unit, $request);
        $this->em->flush();
    }

    private function canEdit(ErrorEntry $entry, AccessScope $scope, User $user): bool
    {
        return $scope->canSetPlans($entry->getOrgUnit()->getId())
            || ($scope->canSeeUnit($entry->getOrgUnit()->getId()) && \in_array($user->getId(), [$entry->getReporter()?->getId(), $entry->getOwner()?->getId()], true));
    }

    private function apply(ErrorEntry $entry, OrgUnit $unit, ErrorEntryRequest $request): void
    {
        $amount = null;
        if (null !== $request->impactAmount && '' !== trim($request->impactAmount)) {
            try {
                $amount = MetricValueNormalizer::normalize(MetricUnit::Eur, $request->impactAmount);
            } catch (\InvalidArgumentException $e) {
                throw new UnprocessableEntityHttpException('Сумма ущерба: '.$e->getMessage(), $e);
            }
            if ($amount->compare(0) < 0) {
                throw new UnprocessableEntityHttpException('Сумма ущерба не может быть отрицательной.');
            }
        }
        if (ErrorImpactType::Money === $entry->getImpactType() && (null === $amount || 0 === $amount->compare(0))) {
            throw new UnprocessableEntityHttpException('Для денежных последствий укажите сумму ущерба в евро.');
        }

        $owner = null;
        if (null !== $request->ownerUserId) {
            foreach ($this->people->people($unit) as $person) {
                if ($person->getId() === $request->ownerUserId) {
                    $owner = $person;
                }
            }
            if (null === $owner) {
                throw new UnprocessableEntityHttpException('Ответственный должен быть сотрудником этого отдела или его ветки.');
            }
        }

        $entry->setImpactAmount($amount)
            ->setCause($this->text($request->cause))
            ->setPrevention($this->text($request->prevention))
            ->setOwner($owner)
            ->setStatus(ErrorStatus::from($request->status));
    }

    private function occurredOn(ErrorEntryRequest $request, ?\DateTimeImmutable $today): \DateTimeImmutable
    {
        $date = $this->date($request->occurredOn) ?? throw new UnprocessableEntityHttpException('Укажите дату ошибки.');
        if ($date > ($today ?? new \DateTimeImmutable())->setTime(0, 0)) {
            throw new UnprocessableEntityHttpException('Дата ошибки не может быть в будущем.');
        }

        return $date;
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
