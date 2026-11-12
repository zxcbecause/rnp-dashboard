<?php

declare(strict_types=1);

namespace App\Service\Metric;

use App\DTO\Request\DeviationNoteRequest;
use App\DTO\Rnp\DeviationNoteDto;
use App\Entity\Auth\User;
use App\Entity\Metric\Metric;
use App\Entity\Metric\MetricDeviationNote;
use App\Enum\PeriodType;
use App\Repository\Metric\MetricRepository;
use App\Security\Access\AccessScopeResolver;
use App\Security\Voter\MetricVoter;
use App\Service\Period\PeriodCalendar;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Reason of a deviation and the action taken, per metric and period (week segment or month).
 */
final readonly class DeviationNoteHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private MetricRepository $metrics,
        private AuthorizationCheckerInterface $authorization,
        private AccessScopeResolver $scopes,
        private PeriodCalendar $calendar,
    ) {
    }

    /**
     * Saves the note; an empty reason removes it. Returns the stored note (null when removed).
     */
    public function save(int $metricId, DeviationNoteRequest $request, User $user): ?DeviationNoteDto
    {
        $metric = $this->visibleMetric($metricId);
        if (!MetricEditPolicy::canExplainDeviation($metric, $this->scopes->resolve($user), $user)) {
            throw new AccessDeniedHttpException('Объяснять отклонения могут руководитель, сотрудники отдела и ответственный за метрику.');
        }

        [$type, $start] = $this->period($request->periodType, $request->periodStart);
        $note = $this->find($metric, $type, $start);
        $reason = trim((string) $request->reason);
        $action = trim((string) $request->action);

        if ('' === $reason) {
            if (null !== $note) {
                $this->em->remove($note);
                $this->em->flush();
            }

            return null;
        }

        if (null === $note) {
            $note = new MetricDeviationNote($metric, $type, $start, $reason, '' === $action ? null : $action, $user);
            $this->em->persist($note);
        } else {
            $note->update($reason, '' === $action ? null : $action, $user);
        }
        $this->em->flush();

        return DeviationNoteDto::fromEntity($note);
    }

    /**
     * Notes of the given metrics for one period each (week segment start for weekly metrics, month start for monthly).
     *
     * @param list<Metric> $metrics
     *
     * @return array<int, MetricDeviationNote> keyed by metric id
     */
    public function notesFor(array $metrics, \DateTimeImmutable $weekStart, \DateTimeImmutable $monthStart): array
    {
        if ([] === $metrics) {
            return [];
        }

        /** @var list<MetricDeviationNote> $notes */
        $notes = $this->em->createQueryBuilder()
            ->select('n')->from(MetricDeviationNote::class, 'n')
            ->where('n.metric IN (:metrics)')
            ->andWhere('(n.periodType = :week AND n.periodStart = :weekStart) OR (n.periodType = :month AND n.periodStart = :monthStart)')
            ->setParameter('metrics', $metrics)
            ->setParameter('week', PeriodType::Week)
            ->setParameter('weekStart', $weekStart->format('Y-m-d'))
            ->setParameter('month', PeriodType::Month)
            ->setParameter('monthStart', $monthStart->format('Y-m-d'))
            ->getQuery()->getResult();

        $byMetric = [];
        foreach ($notes as $note) {
            $weekly = PeriodType::Week === $note->getMetric()->getGranularity();
            if ($weekly === (PeriodType::Week === $note->getPeriodType())) {
                $byMetric[(int) $note->getMetric()->getId()] = $note;
            }
        }

        return $byMetric;
    }

    /**
     * Latest notes of one metric before a date (newest first): earlier explanations give context to new ones.
     *
     * @return list<MetricDeviationNote>
     */
    public function history(Metric $metric, \DateTimeImmutable $before, int $limit = 6): array
    {
        /** @var list<MetricDeviationNote> $notes */
        $notes = $this->em->createQueryBuilder()
            ->select('n')->from(MetricDeviationNote::class, 'n')
            ->where('n.metric = :metric')
            ->andWhere('n.periodStart < :before')
            ->setParameter('metric', $metric)
            ->setParameter('before', $before->format('Y-m-d'))
            ->orderBy('n.periodStart', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        return $notes;
    }

    public function visibleMetric(int $id): Metric
    {
        $metric = $this->metrics->find($id);
        if (null === $metric || !$this->authorization->isGranted(MetricVoter::VIEW, $metric)) {
            throw new NotFoundHttpException('Metric not found.');
        }

        return $metric;
    }

    /**
     * @return array{PeriodType, \DateTimeImmutable}
     */
    public function period(string $rawType, string $rawStart): array
    {
        $type = PeriodType::tryFrom($rawType);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawStart);
        if (!\in_array($type, [PeriodType::Week, PeriodType::Month], true) || false === $date) {
            throw new UnprocessableEntityHttpException('periodType must be week or month, periodStart Y-m-d.');
        }

        return [$type, PeriodType::Week === $type ? $this->calendar->weekSegmentFor($date)->start : $date->modify('first day of this month')];
    }

    private function find(Metric $metric, PeriodType $type, \DateTimeImmutable $start): ?MetricDeviationNote
    {
        return $this->em->getRepository(MetricDeviationNote::class)->findOneBy(['metric' => $metric, 'periodType' => $type, 'periodStart' => $start]);
    }
}
