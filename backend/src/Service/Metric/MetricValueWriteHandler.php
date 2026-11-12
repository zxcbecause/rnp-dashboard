<?php

declare(strict_types=1);

namespace App\Service\Metric;

use App\DTO\Request\MetricPeriodValueRequest;
use App\Entity\Auth\User;
use App\Entity\Metric\Metric;
use App\Entity\Metric\MetricPlan;
use App\Entity\Metric\MetricValue;
use App\Enum\PeriodType;
use App\Enum\ValueOrigin;
use App\Repository\Metric\MetricRepository;
use App\Security\Access\AccessScopeResolver;
use App\Security\Voter\MetricVoter;
use App\Service\Period\PeriodCalendar;
use BcMath\Number;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Manual entry of facts and plans with period and unit validation.
 */
final readonly class MetricValueWriteHandler
{
    public function __construct(
        private MetricRepository $metrics,
        private AuthorizationCheckerInterface $authorization,
        private AccessScopeResolver $scopes,
        private PeriodCalendar $calendar,
        private EntityManagerInterface $em,
    ) {
    }

    public function saveFact(int $metricId, MetricPeriodValueRequest $request, User $user): ?string
    {
        $metric = $this->visibleMetric($metricId);

        if (!$metric->isManual() || null !== $metric->getFormula()) {
            throw new UnprocessableEntityHttpException('Эта метрика считается автоматически, факт вручную не вводится.');
        }
        if (!MetricEditPolicy::canEnterFact($metric, $this->scopes->resolve($user), $user)) {
            throw new AccessDeniedHttpException('Нет прав на ввод факта по этой метрике.');
        }

        $type = PeriodType::from($request->periodType);
        if ($type !== $metric->getGranularity()) {
            throw new UnprocessableEntityHttpException(\sprintf('Факт этой метрики вводится за период «%s».', $metric->getGranularity()->value));
        }

        $start = $this->periodStart($type, $request->periodStart);
        $value = $this->value($metric, $request->value);
        $existing = $this->em->getRepository(MetricValue::class)->findOneBy(['metric' => $metric, 'periodType' => $type, 'periodStart' => $start]);

        if (null === $value) {
            if (null !== $existing) {
                $this->em->remove($existing);
            }
        } elseif (null === $existing) {
            $this->em->persist(new MetricValue($metric, $type, $start, $value, ValueOrigin::Manual, $user));
        } else {
            $existing->update($value, ValueOrigin::Manual, $user);
        }
        $this->em->flush();

        return null === $value ? null : (string) $value;
    }

    public function savePlan(int $metricId, MetricPeriodValueRequest $request, User $user): ?string
    {
        $metric = $this->visibleMetric($metricId);

        if (!MetricEditPolicy::canSetPlan($metric, $this->scopes->resolve($user))) {
            throw new AccessDeniedHttpException('Нет прав на изменение плана по этой метрике.');
        }

        $type = PeriodType::from($request->periodType);
        $start = $this->periodStart($type, $request->periodStart);
        $value = $this->value($metric, $request->value);
        $existing = $this->em->getRepository(MetricPlan::class)->findOneBy(['metric' => $metric, 'periodType' => $type, 'periodStart' => $start]);

        if (null === $value) {
            if (null !== $existing) {
                $this->em->remove($existing);
            }
        } elseif (null === $existing) {
            $this->em->persist(new MetricPlan($metric, $type, $start, $value, $user));
        } else {
            $existing->update($value, $user);
        }
        $this->em->flush();

        return null === $value ? null : (string) $value;
    }

    private function visibleMetric(int $id): Metric
    {
        $metric = $this->metrics->find($id);
        if (null === $metric || !$this->authorization->isGranted(MetricVoter::VIEW, $metric)) {
            throw new NotFoundHttpException('Metric not found.');
        }

        return $metric;
    }

    private function periodStart(PeriodType $type, string $raw): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if (false === $date) {
            throw new UnprocessableEntityHttpException('periodStart must be Y-m-d.');
        }

        $valid = match ($type) {
            PeriodType::Week => $this->calendar->weekSegmentFor($date)->start == $date,
            PeriodType::Month => '01' === $date->format('d'),
            PeriodType::Day => true,
        };

        if (!$valid) {
            throw new UnprocessableEntityHttpException(PeriodType::Week === $type ? 'periodStart must be the first day of a week segment (Monday or the 1st of the month).' : 'periodStart must be the first day of a month.');
        }

        return $date;
    }

    private function value(Metric $metric, string|int|null $raw): ?Number
    {
        if (null === $raw || '' === $raw) {
            return null;
        }

        try {
            return MetricValueNormalizer::normalize($metric->getUnit(), $raw);
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }
    }
}
