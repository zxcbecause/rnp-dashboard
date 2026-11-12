<?php

declare(strict_types=1);

namespace App\Service\Calls;

use App\DTO\Calls\CallExclusionDto;
use App\DTO\Calls\MissedCallDto;
use App\DTO\Calls\MissedCallsDto;
use App\DTO\OrgUnitDto;
use App\DTO\Request\CallExclusionDecisionRequest;
use App\DTO\Request\CallExclusionRequest;
use App\Entity\Auth\User;
use App\Entity\Journal\CallExclusion;
use App\Entity\Journal\CallExclusionAttachment;
use App\Entity\Metric\Metric;
use App\Entity\Org\OrgUnit;
use App\Enum\CallExclusionCategory;
use App\Enum\CallExclusionStatus;
use App\Enum\MetricSourceType;
use App\Security\Access\AccessScope;
use App\Security\Access\AccessScopeResolver;
use App\Service\Metric\AutoMetricRecalculator;
use App\Service\Metric\Calculation\BitrixMetricConfig;
use App\Service\Org\OrgUnitFindHandler;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Incoming calls (Bitrix) of a unit that owns a "missed calls" metric, and requests to leave a call out of the
 * statistics: missed for an objective reason, spam, a test call, a call of our own staff (Gleb, 09.11 and
 * 11.11.2026). An approved exclusion takes the call out of the incoming and of the missed calls.
 *  - request: heads and members of the unit (a reason is required), for calls from the start of the previous
 *    month on — the regular recalculation window; a request of somebody who may decide is approved at once;
 *  - decide (approve / reject, final): heads of the unit and admins; an approval recalculates the Bitrix metrics;
 *  - withdraw a pending request: its author, heads and admins;
 *  - a screenshot may be attached to the request (kept in the database) and is shown to whoever sees the unit.
 * Units without such a metric, foreign units and foreign requests answer 404.
 */
final readonly class CallExclusionHandler
{
    private const int MAX_RANGE_DAYS = 62;

    /** Bitrix OWNER_TYPE_ID → CRM card path. */
    private const array CRM_PATHS = [1 => 'lead', 2 => 'deal', 3 => 'contact', 4 => 'company'];

    public function __construct(
        private EntityManagerInterface $em,
        private Connection $connection,
        private OrgUnitFindHandler $units,
        private AccessScopeResolver $scopes,
        private AutoMetricRecalculator $recalculator,
        #[Autowire('%env(default::BITRIX_WEBHOOK_URL)%')]
        private ?string $bitrixWebhookUrl = null,
    ) {
    }

    public function list(int $orgUnitId, ?string $from, ?string $to, User $user, ?\DateTimeImmutable $today = null, bool $all = false): MissedCallsDto
    {
        $unit = $this->callUnit($orgUnitId);
        $today = ($today ?? new \DateTimeImmutable())->setTime(0, 0);
        $end = $this->date($to) ?? $today;
        $start = $this->date($from) ?? $end->modify('-13 days');
        if ($start > $end) {
            throw new BadRequestHttpException('"from" must not be after "to".');
        }
        if ($start->diff($end)->days >= self::MAX_RANGE_DAYS) {
            throw new BadRequestHttpException(\sprintf('The period must not exceed %d days.', self::MAX_RANGE_DAYS));
        }

        $scope = $this->scopes->resolve($user);
        $canRequest = $scope->canEnterFacts($unit->getId());
        $editableFrom = $this->editableFrom($today);

        /** @var list<array{id: int|string, start_time: string, responsible_id: int|string|null, owner_type_id: int|string|null, owner_id: int|string|null, missed: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, start_time, responsible_id, owner_type_id, owner_id, missed FROM raw_bitrix_call
             WHERE direction = :incoming '.($all ? '' : 'AND missed = 1 ').'AND start_time >= :from AND start_time < :to
             ORDER BY start_time DESC, id DESC',
            [
                'incoming' => BitrixMetricConfig::DIRECTIONS['incoming'],
                'from' => $start->format('Y-m-d'),
                'to' => $end->modify('+1 day')->format('Y-m-d'),
            ],
        );

        $exclusions = [];
        if ([] !== $rows) {
            /** @var list<CallExclusion> $found */
            $found = $this->em->getRepository(CallExclusion::class)->findBy(['callId' => array_map(static fn (array $r): int => (int) $r['id'], $rows)]);
            foreach ($found as $exclusion) {
                $exclusions[$exclusion->getCallId()] = $exclusion;
            }
        }

        /** @var list<CallExclusion> $pending */
        $pending = $this->em->getRepository(CallExclusion::class)->findBy(
            ['orgUnit' => $unit, 'status' => CallExclusionStatus::Pending],
            ['callStartedAt' => 'ASC'],
        );
        $withImage = $this->withImage([...array_values($exclusions), ...$pending]);

        $calls = [];
        foreach ($rows as $row) {
            $startedAt = new \DateTimeImmutable($row['start_time']);
            $exclusion = $exclusions[(int) $row['id']] ?? null;
            $calls[] = new MissedCallDto(
                (int) $row['id'],
                $startedAt->format('Y-m-d\TH:i:s'),
                null === $row['responsible_id'] ? null : (int) $row['responsible_id'],
                $this->crmUrl($row['owner_type_id'], $row['owner_id']),
                $canRequest && null === $exclusion && $startedAt >= $editableFrom,
                null === $exclusion || $exclusion->getOrgUnit() !== $unit ? null : $this->dto($exclusion, $scope, $user, $withImage),
                1 === (int) $row['missed'],
            );
        }

        return new MissedCallsDto(
            OrgUnitDto::fromEntity($unit),
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
            $editableFrom->format('Y-m-d'),
            $canRequest,
            $scope->canSetPlans($unit->getId()),
            $calls,
            array_map(fn (CallExclusion $e): CallExclusionDto => $this->dto($e, $scope, $user, $withImage), $pending),
        );
    }

    /**
     * @return array{id: int, status: string, recalculated: ?bool} recalculated: null while the request waits for a decision
     */
    public function request(int $orgUnitId, CallExclusionRequest $request, User $user, ?\DateTimeImmutable $today = null): array
    {
        $unit = $this->callUnit($orgUnitId);
        $scope = $this->scopes->resolve($user);
        if (!$scope->canEnterFacts($unit->getId())) {
            throw new AccessDeniedHttpException('Отправить звонок на исключение могут руководитель и сотрудники отдела.');
        }

        /** @var array{start_time: string, responsible_id: int|string|null}|false $call */
        $call = $this->connection->fetchAssociative(
            'SELECT start_time, responsible_id FROM raw_bitrix_call WHERE id = :id AND direction = :incoming',
            ['id' => $request->callId, 'incoming' => BitrixMetricConfig::DIRECTIONS['incoming']],
        );
        if (false === $call) {
            throw new UnprocessableEntityHttpException('Такого входящего звонка нет.');
        }
        $startedAt = new \DateTimeImmutable($call['start_time']);
        if ($startedAt < $this->editableFrom(($today ?? new \DateTimeImmutable())->setTime(0, 0))) {
            throw new UnprocessableEntityHttpException('Исключать можно звонки текущего и прошлого месяца.');
        }
        if (null !== $this->em->getRepository(CallExclusion::class)->findOneBy(['callId' => $request->callId])) {
            throw new UnprocessableEntityHttpException('По этому звонку уже есть заявка.');
        }

        $image = null === $request->image || '' === $request->image ? null : CallExclusionImage::fromBase64($request->image);

        $exclusion = new CallExclusion(
            $unit,
            $request->callId,
            $startedAt,
            null === $call['responsible_id'] ? null : (int) $call['responsible_id'],
            trim($request->reason),
            $user,
            CallExclusionCategory::from($request->category),
        );
        $this->em->persist($exclusion);
        if (null !== $image) {
            $this->em->persist(new CallExclusionAttachment($exclusion, $image->contentType, $image->content));
        }
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Two requests for the same call at once (a double click, two managers).
            throw new UnprocessableEntityHttpException('По этому звонку уже есть заявка.', $e);
        }

        // Whoever may decide needs no second step: the request is approved by its author right away.
        $recalculated = null;
        if ($scope->canSetPlans($unit->getId())) {
            try {
                $recalculated = $this->decide((int) $exclusion->getId(), new CallExclusionDecisionRequest(true), $user);
            } catch (ConflictHttpException) {
                // A sync holds the recalculation of an old month: the request stays pending and is decided later.
            }
        }

        return ['id' => (int) $exclusion->getId(), 'status' => $exclusion->getStatus()->value, 'recalculated' => $recalculated];
    }

    /**
     * @return bool whether the metrics were recalculated right away (false: the next sync applies it)
     */
    public function decide(int $id, CallExclusionDecisionRequest $request, User $user): bool
    {
        $exclusion = $this->visible($id, $user);
        if (!$this->scopes->resolve($user)->canSetPlans($exclusion->getOrgUnit()->getId())) {
            throw new AccessDeniedHttpException('Решение по исключению принимает руководитель отдела.');
        }
        $note = null === $request->note ? null : trim($request->note);
        $month = $exclusion->getCallStartedAt()->modify('first day of this month')->setTime(0, 0);

        // The row is locked: of two moderators deciding at once, the second sees the first decision.
        return $this->underRowLock($exclusion, function (CallExclusion $exclusion) use ($request, $user, $note, $month): bool {
            if (CallExclusionStatus::Pending !== $exclusion->getStatus()) {
                throw new UnprocessableEntityHttpException('Решение по этому звонку уже принято.');
            }
            $exclusion->decide($request->approve, $user, '' === $note ? null : $note);
            $this->em->flush();
            if (!$request->approve) {
                return true;
            }

            $recalculated = null !== $this->recalculator->tryRecalculateSource(MetricSourceType::Bitrix, $month);
            // A sync holds the recalculation: it re-counts only its own window, so an older month would keep
            // the call for good while the screen shows it as excluded. Then nothing is saved.
            if (!$recalculated && $month < self::scheduledRecalculationFrom()) {
                throw new ConflictHttpException('Сейчас идёт синхронизация, а звонок старше окна автоматического пересчёта. Повторите через несколько минут.');
            }

            return $recalculated;
        });
    }

    public function withdraw(int $id, User $user): void
    {
        $exclusion = $this->visible($id, $user);
        $scope = $this->scopes->resolve($user);
        // Locked like a decision: a request approved meanwhile is not deleted.
        $this->underRowLock($exclusion, function (CallExclusion $exclusion) use ($scope, $user): void {
            if (!$this->canWithdraw($exclusion, $scope, $user)) {
                throw new AccessDeniedHttpException('Отозвать заявку может её автор или руководитель, пока решение не принято.');
            }
            $this->em->remove($exclusion);
            $this->em->flush();
        });
    }

    /**
     * Pending requests the user may decide on, per unit (for the notification bell).
     *
     * @return list<array{unit: OrgUnit, count: int, oldest: \DateTimeImmutable}>
     */
    public function pendingForModerator(User $user): array
    {
        $scope = $this->scopes->resolve($user);
        /** @var list<CallExclusion> $pending */
        $pending = $this->em->getRepository(CallExclusion::class)->findBy(['status' => CallExclusionStatus::Pending], ['requestedAt' => 'ASC']);

        $byUnit = [];
        foreach ($pending as $exclusion) {
            $unit = $exclusion->getOrgUnit();
            if (!$scope->canSetPlans($unit->getId())) {
                continue;
            }
            $byUnit[(int) $unit->getId()] ??= ['unit' => $unit, 'count' => 0, 'oldest' => $exclusion->getRequestedAt()];
            ++$byUnit[(int) $unit->getId()]['count'];
        }

        return array_values($byUnit);
    }

    /**
     * The user's own requests decided since the given moment (for the notification bell).
     *
     * @return list<CallExclusion>
     */
    public function decidedForRequester(User $user, \DateTimeImmutable $since): array
    {
        /** @var list<CallExclusion> $decided */
        $decided = $this->em->createQueryBuilder()
            ->select('e')->from(CallExclusion::class, 'e')
            ->where('e.requestedBy = :user')->andWhere('e.decidedAt >= :since')->andWhere('e.decidedBy <> :user')
            ->setParameter('user', $user)->setParameter('since', $since)
            ->orderBy('e.decidedAt', \SortDirection::Descending)
            ->getQuery()->getResult();

        return array_values(array_filter($decided, fn (CallExclusion $e): bool => $this->scopes->resolve($user)->canSeeUnit($e->getOrgUnit()->getId())));
    }

    /**
     * A unit that owns an active "missed incoming calls" metric computed from Bitrix; anything else is 404.
     */
    private function callUnit(int $orgUnitId): OrgUnit
    {
        $unit = $this->units->handle($orgUnitId);
        if (!isset($this->callUnitsById()[(int) $unit->getId()])) {
            throw new NotFoundHttpException('Org unit not found.');
        }

        return $unit;
    }

    /**
     * Units owning an active "missed incoming calls" metric computed from Bitrix.
     *
     * @return array<int, OrgUnit>
     */
    private function callUnitsById(): array
    {
        /** @var list<Metric> $metrics */
        $metrics = $this->em->getRepository(Metric::class)->findBy(['sourceType' => MetricSourceType::Bitrix, 'isActive' => true]);
        $units = [];
        foreach ($metrics as $metric) {
            try {
                $config = BitrixMetricConfig::fromArray($metric->getSourceConfig());
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (BitrixMetricConfig::CALLS === $config?->calculator && true === $config->missed) {
                $units[(int) $metric->getOrgUnit()->getId()] = $metric->getOrgUnit();
            }
        }

        return $units;
    }

    private function visible(int $id, User $user): CallExclusion
    {
        $exclusion = $this->em->find(CallExclusion::class, $id);
        if (null === $exclusion || !$this->scopes->resolve($user)->canSeeUnit($exclusion->getOrgUnit()->getId())) {
            throw new NotFoundHttpException('Call exclusion not found.');
        }

        return $exclusion;
    }

    private function canWithdraw(CallExclusion $exclusion, AccessScope $scope, User $user): bool
    {
        return CallExclusionStatus::Pending === $exclusion->getStatus()
            && ($scope->canSetPlans($exclusion->getOrgUnit()->getId()) || $exclusion->getRequestedBy()?->getId() === $user->getId());
    }

    /**
     * @param array<int, true> $withImage ids of the requests with a screenshot (withImage())
     */
    private function dto(CallExclusion $exclusion, AccessScope $scope, User $user, array $withImage = []): CallExclusionDto
    {
        return CallExclusionDto::fromEntity($exclusion, $this->canWithdraw($exclusion, $scope, $user), isset($withImage[(int) $exclusion->getId()]));
    }

    /**
     * Requests are accepted while the call is inside the regular recalculation window (two months back).
     */
    private function editableFrom(\DateTimeImmutable $today): \DateTimeImmutable
    {
        return $today->modify('first day of last month')->setTime(0, 0);
    }

    private function crmUrl(int|string|null $ownerType, int|string|null $ownerId): ?string
    {
        $path = self::CRM_PATHS[(int) $ownerType] ?? null;
        $parts = null === $this->bitrixWebhookUrl || '' === $this->bitrixWebhookUrl ? false : parse_url($this->bitrixWebhookUrl);
        if (null === $path || null === $ownerId || false === $parts || !isset($parts['host'])) {
            return null;
        }

        // Only the portal host: the webhook path carries the secret token.
        return \sprintf('https://%s/crm/%s/details/%d/', $parts['host'], $path, (int) $ownerId);
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

    /**
     * Units with missed calls the user can see (menu entry and unit choice of the missed-calls screen).
     *
     * @return list<OrgUnit>
     */
    public function visibleCallUnits(User $user): array
    {
        $scope = $this->scopes->resolve($user);

        return array_values(array_filter($this->callUnitsById(), static fn (OrgUnit $unit): bool => $scope->canSeeUnit($unit->getId())));
    }

    /**
     * Runs $change in a transaction with the exclusion re-read under SELECT … FOR UPDATE; any exception rolls back.
     *
     * @template T
     *
     * @param callable(CallExclusion): T $change
     *
     * @return T
     */
    private function underRowLock(CallExclusion $exclusion, callable $change): mixed
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->refresh($exclusion, LockMode::PESSIMISTIC_WRITE);
            $result = $change($exclusion);
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    /**
     * Start of the window the scheduled syncs recalculate (RecalculateMetricsCommand default).
     */
    private static function scheduledRecalculationFrom(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('first day of -2 months'))->setTime(0, 0);
    }

    /**
     * Screenshot of a request, for whoever sees its unit; 404 when the request has none.
     *
     * @return array{contentType: string, data: string} data: base64 (the frontend proxy is JSON-only)
     */
    public function image(int $id, User $user): array
    {
        $exclusion = $this->visible($id, $user);
        $attachment = $this->em->getRepository(CallExclusionAttachment::class)->findOneBy(['exclusion' => $exclusion]);
        if (null === $attachment) {
            throw new NotFoundHttpException('Call exclusion image not found.');
        }

        return ['contentType' => $attachment->getContentType(), 'data' => base64_encode($attachment->getContent())];
    }

    /**
     * Which of the requests carry a screenshot, without loading the images.
     *
     * @param list<CallExclusion> $exclusions
     *
     * @return array<int, true>
     */
    private function withImage(array $exclusions): array
    {
        if ([] === $exclusions) {
            return [];
        }

        /** @var list<int|string> $ids */
        $ids = $this->em->createQueryBuilder()
            ->select('IDENTITY(a.exclusion)')->from(CallExclusionAttachment::class, 'a')
            ->where('a.exclusion IN (:exclusions)')->setParameter('exclusions', $exclusions)
            ->getQuery()->getSingleColumnResult();

        return array_fill_keys(array_map(intval(...), $ids), true);
    }
}
