<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\DTO\IngestResultDto;
use App\Entity\Metric\Metric;
use App\Entity\Metric\MetricValue;
use App\Entity\Sync\IngestToken;
use App\Entity\Sync\SyncRun;
use App\Enum\MetricSourceType;
use App\Enum\PeriodType;
use App\Enum\SyncSource;
use App\Enum\TimeAggregation;
use App\Enum\ValueOrigin;
use App\Repository\Org\OrgUnitPathRepository;
use App\Service\Metric\MetricValueNormalizer;
use App\Service\Period\PeriodCalendar;
use BcMath\Number;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * POST /api/ingest/metric-values: facts pushed by a source script.
 *
 *  {"values": [{"metric": "cs_nps", "date": "2026-11-04", "value": "8.5"}, ...]}
 *
 *  - only active, stored (non-formula) push_api metrics of the token's branch; anything else —
 *    missing or foreign alike — is "unknown metric";
 *  - "date" is any day of the period: it is mapped to the metric's period (week segment / month);
 *    future periods are rejected;
 *  - "value" is a decimal string (numbers are accepted), typed by the metric unit; null removes the fact;
 *  - all or nothing: one invalid item rejects the whole request with per-item violations;
 *  - origin = auto; every accepted request leaves a sync_run (source push_api, scope = token name).
 */
final readonly class MetricIngestHandler
{
    public const int MAX_ITEMS = 500;

    public function __construct(
        private EntityManagerInterface $em,
        private OrgUnitPathRepository $paths,
        private PeriodCalendar $calendar,
    ) {
    }

    /**
     * @param array<mixed> $payload decoded request body
     */
    public function ingest(IngestToken $token, array $payload, ?\DateTimeImmutable $today = null): IngestResultDto
    {
        $today = ($today ?? new \DateTimeImmutable())->setTime(0, 0);
        $violations = new ConstraintViolationList();

        $items = $payload['values'] ?? null;
        if (!\is_array($items) || !array_is_list($items) || [] === $items || \count($items) > self::MAX_ITEMS) {
            $this->fail($payload, $violations, 'values', \sprintf('A list of 1 to %d items is required.', self::MAX_ITEMS));
        }
        /** @var list<mixed> $items */
        $metrics = $this->allowedMetrics($token, $items);

        $writes = [];
        foreach ($items as $i => $item) {
            $path = \sprintf('values[%d]', $i);
            if (!\is_array($item)) {
                $this->add($violations, $path, 'An object {metric, date, value} is expected.');
                continue;
            }

            $metric = \is_string($item['metric'] ?? null) ? ($metrics[$item['metric']] ?? null) : null;
            if (null === $metric) {
                $this->add($violations, $path.'.metric', 'Unknown metric (or not writable by this token).');
                continue;
            }

            $date = \is_string($item['date'] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $item['date']) : false;
            if (false === $date || $date->format('Y-m-d') !== $item['date']) {
                $this->add($violations, $path.'.date', 'A date Y-m-d is expected.');
                continue;
            }
            $start = $this->periodStart($metric->getGranularity(), $date);
            if ($start > $today) {
                $this->add($violations, $path.'.date', 'The period has not started yet.');
                continue;
            }

            $key = $metric->getCode().'|'.$start->format('Y-m-d');
            if (isset($writes[$key])) {
                $this->add($violations, $path, \sprintf('Duplicate of values[%d]: same metric and period.', $writes[$key][3]));
                continue;
            }

            if (!\array_key_exists('value', $item)) {
                $this->add($violations, $path.'.value', 'A value (or null to remove) is required.');
                continue;
            }
            try {
                $value = $this->value($metric, $item['value']);
            } catch (\InvalidArgumentException $e) {
                $this->add($violations, $path.'.value', $e->getMessage());
                continue;
            }

            $writes[$key] = [$metric, $start, $value, $i];
        }

        if (\count($violations) > 0) {
            throw new UnprocessableEntityHttpException('Validation failed.', new ValidationFailedException($payload, $violations));
        }

        return $this->write($token, array_values($writes));
    }

    /**
     * @param list<array{Metric, \DateTimeImmutable, ?Number, int}> $writes
     */
    private function write(IngestToken $token, array $writes): IngestResultDto
    {
        $run = new SyncRun(SyncSource::PushApi, $token->getName());
        $result = new IngestResultDto();

        foreach ($writes as [$metric, $start, $value]) {
            $type = $metric->getGranularity();
            $existing = $this->em->getRepository(MetricValue::class)->findOneBy(['metric' => $metric, 'periodType' => $type, 'periodStart' => $start]);

            if (null === $value) {
                if (null !== $existing) {
                    $this->em->remove($existing);
                    ++$result->removed;
                } else {
                    ++$result->unchanged;
                }
            } elseif (null === $existing) {
                $this->em->persist(new MetricValue($metric, $type, $start, $value, ValueOrigin::Auto));
                ++$result->written;
            } elseif (0 !== $existing->getValue()->compare($value) || ValueOrigin::Auto !== $existing->getOrigin()) {
                $existing->update($value, ValueOrigin::Auto, null);
                ++$result->written;
            } else {
                ++$result->unchanged;
            }
        }

        $run->succeed($result->written + $result->removed);
        $token->markUsed();
        $this->em->persist($run);
        $this->em->flush();

        return $result;
    }

    /**
     * @param list<mixed> $items
     *
     * @return array<string, Metric> code => metric the token may write
     */
    private function allowedMetrics(IngestToken $token, array $items): array
    {
        $codes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): ?string => \is_array($item) && \is_string($item['metric'] ?? null) ? $item['metric'] : null,
            $items,
        ))));
        if ([] === $codes) {
            return [];
        }

        $branch = $this->paths->findBranchIds([(int) $token->getOrgUnit()->getId()]);

        /** @var list<Metric> $found */
        $found = $this->em->getRepository(Metric::class)->findBy(['code' => $codes, 'isActive' => true, 'sourceType' => MetricSourceType::PushApi]);

        $allowed = [];
        foreach ($found as $metric) {
            if (TimeAggregation::Formula !== $metric->getTimeAgg() && \in_array($metric->getOrgUnit()->getId(), $branch, true)) {
                $allowed[$metric->getCode()] = $metric;
            }
        }

        return $allowed;
    }

    private function periodStart(PeriodType $type, \DateTimeImmutable $date): \DateTimeImmutable
    {
        return match ($type) {
            PeriodType::Week => $this->calendar->weekSegmentFor($date)->start,
            PeriodType::Month => $date->modify('first day of this month'),
            PeriodType::Day => $date,
        };
    }

    /**
     * JSON numbers are accepted for convenience; json_encode gives their shortest exact form ("12.5").
     */
    private function value(Metric $metric, mixed $raw): ?Number
    {
        if (null === $raw) {
            return null;
        }
        if (\is_float($raw)) {
            $raw = json_encode($raw, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
        }
        if (!\is_string($raw) && !\is_int($raw)) {
            throw new \InvalidArgumentException('A decimal string, a number or null is expected.');
        }

        return MetricValueNormalizer::normalize($metric->getUnit(), $raw);
    }

    private function add(ConstraintViolationList $violations, string $path, string $message): void
    {
        $violations->add(new ConstraintViolation($message, $message, [], null, $path, null));
    }

    /**
     * @param array<mixed> $payload
     */
    private function fail(array $payload, ConstraintViolationList $violations, string $path, string $message): never
    {
        $this->add($violations, $path, $message);

        throw new UnprocessableEntityHttpException('Validation failed.', new ValidationFailedException($payload, $violations));
    }
}
