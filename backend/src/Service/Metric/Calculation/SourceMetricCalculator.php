<?php

declare(strict_types=1);

namespace App\Service\Metric\Calculation;

use App\Enum\MetricSourceType;
use BcMath\Number;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Computes the facts of metrics of one source type from its raw_* snapshots (see AutoMetricRecalculator).
 * The parsed config is opaque to the caller: whatever parseConfig() returned is passed back.
 */
#[AutoconfigureTag(self::TAG)]
interface SourceMetricCalculator
{
    public const string TAG = 'app.source_metric_calculator';

    public function sourceType(): MetricSourceType;

    /**
     * @param array<string, mixed>|null $config metric.source_config
     *
     * @return object|null null when the metric has no calculator configured
     *
     * @throws \InvalidArgumentException on an invalid config
     */
    public function parseConfig(?array $config): ?object;

    /**
     * false until the source snapshot has been synced at least once: an empty table must not
     * turn imported or seeded facts into zeros.
     */
    public function hasSourceData(object $config): bool;

    /**
     * Value for [start, end); dates are Madrid wall time.
     */
    public function calculate(object $config, \DateTimeImmutable $start, \DateTimeImmutable $end): Number;

    /**
     * true for a value that describes the moment it is computed at (a forecast): only the running period is
     * computed, a finished period keeps what it had — recomputing it later would rewrite history with hindsight.
     */
    public function isPointInTime(object $config): bool;
}
