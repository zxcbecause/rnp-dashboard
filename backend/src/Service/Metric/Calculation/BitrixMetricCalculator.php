<?php

declare(strict_types=1);

namespace App\Service\Metric\Calculation;

use App\Enum\CallExclusionStatus;
use App\Enum\MetricSourceType;
use BcMath\Number;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Computes bitrix metrics for [start, end) from the raw_bitrix_* snapshots (see BitrixMetricConfig).
 * Dates are Madrid wall time; `end` is exclusive.
 */
final readonly class BitrixMetricCalculator implements SourceMetricCalculator
{
    public function __construct(private Connection $connection)
    {
    }

    public function sourceType(): MetricSourceType
    {
        return MetricSourceType::Bitrix;
    }

    public function parseConfig(?array $config): ?BitrixMetricConfig
    {
        return BitrixMetricConfig::fromArray($config);
    }

    public function hasSourceData(object $config): bool
    {
        $table = match ($this->config($config)->calculator) {
            BitrixMetricConfig::DEALS_CREATED => 'raw_bitrix_deal',
            BitrixMetricConfig::DEALS_REACHED_STAGE, BitrixMetricConfig::DEALS_COHORT_REACHED_STAGE => 'raw_bitrix_deal_stage_history',
            default => 'raw_bitrix_call',
        };

        return false !== $this->connection->fetchOne(\sprintf('SELECT 1 FROM %s LIMIT 1', $table));
    }

    public function calculate(object $config, \DateTimeImmutable $start, \DateTimeImmutable $end): Number
    {
        $config = $this->config($config);
        $period = ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];

        return match ($config->calculator) {
            BitrixMetricConfig::DEALS_CREATED => $this->dealsCreated($config, $period),
            BitrixMetricConfig::DEALS_REACHED_STAGE => $this->dealsReachedStage($config, $period),
            BitrixMetricConfig::CALLS => $this->calls($config, $period),
            BitrixMetricConfig::DEALS_COHORT_REACHED_STAGE => $this->cohortReachedStage($config, $period),
            default => throw new \LogicException(\sprintf('Calculator "%s" is not implemented.', $config->calculator)),
        };
    }

    /**
     * @param array{start: string, end: string} $period
     */
    private function dealsCreated(BitrixMetricConfig $config, array $period): Number
    {
        [$sql, $params, $types] = $this->sourceFilter($config);

        if ([] !== $config->categories) {
            $sql .= ' AND d.category_id IN (:categories)';
            $params['categories'] = $config->categories;
            $types['categories'] = ArrayParameterType::INTEGER;
        }

        return $this->number(
            'SELECT COUNT(*) FROM raw_bitrix_deal d WHERE d.date_create >= :start AND d.date_create < :end'.$sql,
            [...$params, ...$period],
            $types,
        );
    }

    /**
     * Each deal counts once, in the period of its first move into any of the stages
     * (moving back and forth does not count it again).
     *
     * @param array{start: string, end: string} $period
     */
    private function dealsReachedStage(BitrixMetricConfig $config, array $period): Number
    {
        [$sql, $params, $types] = $this->sourceFilter($config);

        return $this->number(
            'SELECT COUNT(*) FROM (
                SELECT MIN(h.created_time) AS reached_at
                FROM raw_bitrix_deal_stage_history h
                LEFT JOIN raw_bitrix_deal d ON d.id = h.deal_id
                WHERE h.stage_id IN (:stages)'.$sql.'
                GROUP BY h.deal_id
            ) x WHERE x.reached_at >= :start AND x.reached_at < :end',
            [...$params, ...$period, 'stages' => $config->stages],
            [...$types, 'stages' => ArrayParameterType::STRING],
        );
    }

    /**
     * Lost calls (no_follow_up_hours) are missed calls with no outgoing or answered call of the same CRM entity within
     * the window; a missed call with no CRM binding is lost when not followed up.
     *
     * Incoming calls leave out the ones the head approved to exclude (call_exclusion: spam, test and own calls,
     * calls missed for a technical reason) — from the total and from the missed ones alike (Gleb, 11.11.2026).
     *
     * @param array{start: string, end: string} $period
     */
    private function calls(BitrixMetricConfig $config, array $period): Number
    {
        $sql = 'SELECT COUNT(*) FROM raw_bitrix_call c WHERE c.direction = :direction AND c.start_time >= :start AND c.start_time < :end';
        $params = [...$period, 'direction' => $config->direction];

        if (null !== $config->missed) {
            $sql .= ' AND c.missed = :missed';
            $params['missed'] = $config->missed ? 1 : 0;
        }
        if (BitrixMetricConfig::DIRECTIONS['incoming'] === $config->direction) {
            $sql .= ' AND NOT EXISTS (SELECT 1 FROM call_exclusion e WHERE e.call_id = c.id AND e.status = :approved)';
            $params['approved'] = CallExclusionStatus::Approved->value;
        }
        if (null !== $config->noFollowUpHours) {
            // Settled calls only: a call younger than the window may still get its follow-up.
            $sql .= ' AND c.start_time <= :settled_before
                AND NOT EXISTS (
                    SELECT 1 FROM raw_bitrix_call f
                    WHERE f.owner_type_id = c.owner_type_id AND f.owner_id = c.owner_id AND f.id <> c.id
                      AND f.start_time > c.start_time AND f.start_time <= c.start_time + INTERVAL :hours HOUR
                      AND (f.direction = :outgoing OR f.missed = 0)
                )';
            $params['settled_before'] = (new \DateTimeImmutable(\sprintf('-%d hours', $config->noFollowUpHours)))->format('Y-m-d H:i:s');
            $params['hours'] = $config->noFollowUpHours;
            $params['outgoing'] = BitrixMetricConfig::DIRECTIONS['outgoing'];
        }

        return $this->number($sql, $params, []);
    }

    /**
     * Excludes deals by the label of their "Source" field; deals without a source always count.
     *
     * @return array{string, array<string, mixed>, array<string, ArrayParameterType>}
     */
    private function sourceFilter(BitrixMetricConfig $config): array
    {
        if ([] === $config->excludeOwnerSources) {
            return ['', [], []];
        }

        return [
            ' AND (d.owner_source IS NULL OR d.owner_source NOT IN (:exclude_sources))',
            ['exclude_sources' => $config->excludeOwnerSources],
            ['exclude_sources' => ArrayParameterType::STRING],
        ];
    }

    /**
     * @param array<string, mixed>              $params
     * @param array<string, ArrayParameterType> $types
     */
    private function number(string $sql, array $params, array $types): Number
    {
        $value = $this->connection->fetchOne($sql, $params, $types);

        return new Number(is_numeric($value) ? (string) $value : '0');
    }

    private function config(object $config): BitrixMetricConfig
    {
        return $config instanceof BitrixMetricConfig ? $config : throw new \LogicException(\sprintf('Expected %s, got %s.', BitrixMetricConfig::class, $config::class));
    }

    /**
     * Deals created in the period (same filter as deals_created) that have ever entered one of the stages.
     *
     * @param array{start: string, end: string} $period
     */
    private function cohortReachedStage(BitrixMetricConfig $config, array $period): Number
    {
        [$sql, $params, $types] = $this->sourceFilter($config);

        if ([] !== $config->categories) {
            $sql .= ' AND d.category_id IN (:categories)';
            $params['categories'] = $config->categories;
            $types['categories'] = ArrayParameterType::INTEGER;
        }

        return $this->number(
            'SELECT COUNT(*) FROM raw_bitrix_deal d
             WHERE d.date_create >= :start AND d.date_create < :end'.$sql.'
               AND EXISTS (SELECT 1 FROM raw_bitrix_deal_stage_history h WHERE h.deal_id = d.id AND h.stage_id IN (:stages))',
            [...$params, ...$period, 'stages' => $config->stages],
            [...$types, 'stages' => ArrayParameterType::STRING],
        );
    }

    public function isPointInTime(object $config): bool
    {
        return false;
    }
}
