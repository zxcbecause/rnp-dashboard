<?php

declare(strict_types=1);

namespace App\Service\Metric\Calculation;

/**
 * Parsed and validated metric.source_config of a bitrix metric:
 *
 *   {"calculator": "deals_created", "categories": [4, 20], "exclude_owner_sources": ["Cold call"]}
 *       deals created in the period (by DATE_CREATE);
 *   {"calculator": "deals_reached_stage", "stages": ["C4:UC_G88IW0", "C4:WON", ...], "exclude_owner_sources": [...]}
 *       deals whose first move into any listed stage happened in the period (stage history).
 *       Stages are listed explicitly: pipeline order does not separate progress from loss
 *       ("No localizable" sorts after "Contrato firmado" in pipeline 20);
 *   {"calculator": "calls", "direction": "incoming", "missed": true}
 *       call activities started in the period; "missed" omitted = all;
 *   {"calculator": "calls", "direction": "incoming", "missed": true, "no_follow_up_hours": 24}
 *       lost calls: missed ones with no follow-up within N hours — no outgoing and no answered call of the same
 *       CRM entity (lead / deal / contact / company). A call counts only once its N hours have passed.
 *   {"calculator": "deals_cohort_reached_stage", "categories": [4, 20], "stages": [...], "exclude_owner_sources": [...]}
 *       cohort: deals CREATED in the period that have reached any listed stage by now (whenever it happened).
 *       The value of a month keeps growing while its deals progress; used by monthly_cohort metrics.
 */
final readonly class BitrixMetricConfig
{
    public const string DEALS_CREATED = 'deals_created';
    public const string DEALS_REACHED_STAGE = 'deals_reached_stage';
    public const string CALLS = 'calls';
    public const string DEALS_COHORT_REACHED_STAGE = 'deals_cohort_reached_stage';

    private const array CALCULATORS = [self::DEALS_CREATED, self::DEALS_REACHED_STAGE, self::CALLS, self::DEALS_COHORT_REACHED_STAGE];

    /** Bitrix activity DIRECTION values. */
    public const array DIRECTIONS = ['incoming' => 1, 'outgoing' => 2];

    /**
     * @param list<int>    $categories          empty = any pipeline (deals_created)
     * @param list<string> $stages              deals_reached_stage
     * @param list<string> $excludeOwnerSources labels of the deal "Source" field
     */
    private function __construct(
        public string $calculator,
        public array $categories,
        public array $stages,
        public array $excludeOwnerSources,
        public int $direction,
        public ?bool $missed,
        public ?int $noFollowUpHours = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $config
     *
     * @return self|null null when the metric is not computed from Bitrix data
     */
    public static function fromArray(?array $config): ?self
    {
        $calculator = $config['calculator'] ?? null;
        if (null === $config || null === $calculator) {
            return null;
        }

        if (!\in_array($calculator, self::CALCULATORS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown calculator "%s".', \is_scalar($calculator) ? $calculator : get_debug_type($calculator)));
        }

        $categories = $config['categories'] ?? [];
        if (!\is_array($categories) || [] !== array_filter($categories, static fn ($c): bool => !\is_int($c) || $c < 0)) {
            throw new \InvalidArgumentException('"categories" must be a list of pipeline ids.');
        }

        $stages = self::strings($config, 'stages');
        if (\in_array($calculator, [self::DEALS_REACHED_STAGE, self::DEALS_COHORT_REACHED_STAGE], true) && [] === $stages) {
            throw new \InvalidArgumentException('"stages" must list at least one stage id.');
        }

        $direction = $config['direction'] ?? 'incoming';
        if (!\is_string($direction) || !isset(self::DIRECTIONS[$direction])) {
            throw new \InvalidArgumentException('"direction" must be "incoming" or "outgoing".');
        }

        $missed = $config['missed'] ?? null;
        if (null !== $missed && !\is_bool($missed)) {
            throw new \InvalidArgumentException('"missed" must be true, false or omitted.');
        }

        $noFollowUpHours = $config['no_follow_up_hours'] ?? null;
        if (null !== $noFollowUpHours && (!\is_int($noFollowUpHours) || $noFollowUpHours < 1 || $noFollowUpHours > 168 || true !== $missed)) {
            throw new \InvalidArgumentException('"no_follow_up_hours" must be an integer from 1 to 168 and goes with "missed": true.');
        }

        return new self(
            $calculator,
            array_values($categories),
            $stages,
            self::strings($config, 'exclude_owner_sources'),
            self::DIRECTIONS[$direction],
            $missed,
            $noFollowUpHours,
        );
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private static function strings(array $config, string $key): array
    {
        $value = $config[$key] ?? [];
        if (!\is_array($value) || [] !== array_filter($value, static fn ($v): bool => !\is_string($v) || '' === $v)) {
            throw new \InvalidArgumentException(\sprintf('"%s" must be a list of non-empty strings.', $key));
        }

        /* @var list<string> */
        return array_values($value);
    }
}
