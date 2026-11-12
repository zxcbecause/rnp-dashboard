<?php

declare(strict_types=1);

namespace App\Service\Metric;

use App\Entity\Metric\Metric;
use App\Entity\Metric\MetricValue;
use App\Entity\Org\OrgUnit;
use App\Enum\MetricDirection;
use App\Enum\MetricSourceType;
use App\Enum\MetricUnit;
use App\Enum\PeriodType;
use App\Enum\TimeAggregation;
use App\Enum\TreeAggregation;
use App\Enum\ValueOrigin;
use App\Repository\Metric\MetricRepository;
use App\Service\Metric\Calculation\BitrixMetricConfig;
use App\Service\Metric\Calculation\PlatformMetricConfig;
use App\Service\Metric\Formula\FormulaEvaluator;
use App\Service\Metric\Formula\FormulaException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Metric definitions from a YAML catalog (see config/metrics/): units by name, metrics by code.
 * Creates missing metrics and brings existing ones to the catalog definition; values, plans and the user's
 * personal views are not touched. "plan_formula" computes the plan from other metrics (see Metric::$planFormula);
 * "daily_avg: true" shows the average per day next to the totals in the RNP;
 * "source" (manual by default) with "source_config" makes the metric computed from the platform or Bitrix. Validation happens before anything is written.
 */
final readonly class MetricCatalogLoader
{
    public function __construct(
        private EntityManagerInterface $em,
        private MetricRepository $metrics,
        private FormulaEvaluator $formulas,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /**
     * @return list<array{unit: string, code: string, name: string, action: string}>
     */
    public function load(string $file, bool $dryRun = false): array
    {
        $path = str_starts_with($file, '/') ? $file : $this->projectDir.'/'.$file;
        if (!is_file($path)) {
            throw new \InvalidArgumentException(\sprintf('Catalog file %s not found.', $file));
        }
        $catalog = Yaml::parseFile($path);
        if (!\is_array($catalog) || !\is_array($catalog['units'] ?? null)) {
            throw new \InvalidArgumentException('The catalog needs a "units" map.');
        }

        $plan = [];
        foreach ($catalog['units'] as $unitName => $unitDef) {
            $unit = $this->em->getRepository(OrgUnit::class)->findOneBy(['name' => (string) $unitName])
                ?? throw new \InvalidArgumentException(\sprintf('Org unit "%s" not found.', $unitName));
            $sort = (int) ($unitDef['sort_from'] ?? 1000);
            foreach ($unitDef['metrics'] ?? [] as $def) {
                $plan[] = [$unit, $this->definition($def), $sort += 10];
            }
        }

        $report = [];
        $nowComputed = [];
        foreach ($plan as [$unit, $def, $sort]) {
            $metric = $this->metrics->findOneBy(['code' => $def['code']]);
            $action = null === $metric ? 'created' : 'unchanged';
            if (null === $metric) {
                $metric = new Metric($unit, $def['code'], $def['name'], $def['unit']);
                $metric->setSort($sort)->setSourceType(MetricSourceType::Manual);
                if (!$dryRun) {
                    $this->em->persist($metric);
                }
            } elseif ($metric->getOrgUnit() !== $unit) {
                throw new \InvalidArgumentException(\sprintf('Metric %s already belongs to "%s".', $def['code'], $metric->getOrgUnit()->getName()));
            }

            if ('created' !== $action && $this->differs($metric, $def)) {
                $action = 'updated';
            }
            if ('created' !== $action && MetricSourceType::Manual === $metric->getSourceType() && MetricSourceType::Manual !== $def['source']) {
                $nowComputed[] = $metric;
            }
            if (!$dryRun) {
                $this->apply($metric, $def, $unit);
            }
            $report[] = ['unit' => (string) $unit->getName(), 'code' => $def['code'], 'name' => $def['name'], 'action' => $action];
        }

        if (!$dryRun) {
            $this->em->flush();
            // A metric that becomes computed: facts typed in while it was manual can no longer be edited in the
            // dashboard and would be kept by the recalculation for good, mixed with computed weeks. Marked as
            // imported, they are replaced by the next recalculation.
            foreach ($nowComputed as $metric) {
                $this->em->createQueryBuilder()
                    ->update(MetricValue::class, 'v')
                    ->set('v.origin', ':import')
                    ->where('v.metric = :metric')
                    ->andWhere('v.origin = :manual')
                    ->setParameter('import', ValueOrigin::Import)
                    ->setParameter('manual', ValueOrigin::Manual)
                    ->setParameter('metric', $metric)
                    ->getQuery()
                    ->execute();
            }
        }

        return $report;
    }

    /**
     * @return array{code: string, name: string, unit: MetricUnit, direction: MetricDirection, timeAgg: TimeAggregation, granularity: PeriodType, formula: ?string, planFormula: ?string, dailyAvg: bool, source: MetricSourceType, sourceConfig: ?array<string, mixed>, card: list<?string>}
     */
    private function definition(mixed $def): array
    {
        if (!\is_array($def) || !\is_string($def['code'] ?? null) || !\is_string($def['name'] ?? null)
            || 1 !== preg_match('/^[a-z][a-z0-9_]{2,63}$/', $def['code'])) {
            throw new \InvalidArgumentException('Every metric needs a code (a-z, 0-9, _) and a name.');
        }
        $timeAgg = TimeAggregation::from((string) ($def['time_agg'] ?? 'sum'));
        $formula = isset($def['formula']) ? (string) $def['formula'] : null;
        if ((TimeAggregation::Formula === $timeAgg) !== (null !== $formula)) {
            throw new \InvalidArgumentException(\sprintf('%s: time_agg formula and a formula go together.', $def['code']));
        }
        $planFormula = isset($def['plan_formula']) ? (string) $def['plan_formula'] : null;
        foreach (array_filter([$formula, $planFormula]) as $expression) {
            try {
                $this->formulas->references($expression);
            } catch (FormulaException $e) {
                throw new \InvalidArgumentException(\sprintf('%s: %s', $def['code'], $e->getMessage()), 0, $e);
            }
        }
        $dailyAvg = $def['daily_avg'] ?? false;
        if (!\is_bool($dailyAvg)) {
            throw new \InvalidArgumentException(\sprintf('%s: daily_avg must be true or false.', $def['code']));
        }
        $card = array_values(array_map(static fn (mixed $v): ?string => null === $v ? null : (string) $v, (array) ($def['card'] ?? [])));
        [$source, $sourceConfig] = $this->source($def['code'], $def['source'] ?? 'manual', $def['source_config'] ?? null, null !== $formula);

        return [
            'code' => $def['code'],
            'name' => $def['name'],
            'unit' => MetricUnit::from((string) ($def['unit'] ?? 'count')),
            'direction' => MetricDirection::from((string) ($def['direction'] ?? 'higher_better')),
            'timeAgg' => $timeAgg,
            'granularity' => PeriodType::from((string) ($def['granularity'] ?? 'week')),
            'formula' => $formula,
            'planFormula' => $planFormula,
            'dailyAvg' => $dailyAvg,
            'source' => $source,
            'sourceConfig' => $sourceConfig,
            'card' => $card,
        ];
    }

    /**
     * @param array{code: string, name: string, unit: MetricUnit, direction: MetricDirection, timeAgg: TimeAggregation, granularity: PeriodType, formula: ?string, planFormula: ?string, dailyAvg: bool, source: MetricSourceType, sourceConfig: ?array<string, mixed>, card: list<?string>} $def
     */
    private function apply(Metric $metric, array $def, OrgUnit $unit): void
    {
        $formula = TimeAggregation::Formula === $def['timeAgg'];
        $metric->setName($def['name'])
            ->setUnit($def['unit'])
            ->setDirection($def['direction'])
            ->setTimeAgg($def['timeAgg'])
            // Formulas are recomputed at every level; additive and "last" values roll up as sums.
            ->setTreeAgg($formula ? TreeAggregation::Formula : TreeAggregation::Sum)
            ->setFormula($def['formula'])
            ->setPlanFormula($def['planFormula'])
            ->setShowDailyAvg($def['dailyAvg'])
            ->setSourceType($def['source'])
            ->setSourceConfig($def['sourceConfig'])
            ->setGranularity($def['granularity'])
            ->setWhatMeasured($def['card'][0] ?? null)
            ->setFormulaText($def['card'][1] ?? null)
            ->setSourceText($def['card'][2] ?? null)
            ->setPeriodText($def['card'][3] ?? null)
            ->setUsedIn($def['card'][4] ?? null);

        // The unit head answers for the numbers by default; never overwrite an owner set by hand.
        $metric->setOwnerUser($metric->getOwnerUser() ?? $unit->getHeadUser());
        // Nobody fills formulas and computed metrics.
        $metric->setFillerUser($formula || MetricSourceType::Manual !== $def['source'] ? null : ($metric->getFillerUser() ?? $unit->getHeadUser()));
    }

    /**
     * @param array{code: string, name: string, unit: MetricUnit, direction: MetricDirection, timeAgg: TimeAggregation, granularity: PeriodType, formula: ?string, planFormula: ?string, dailyAvg: bool, source: MetricSourceType, sourceConfig: ?array<string, mixed>, card: list<?string>} $def
     */
    private function differs(Metric $metric, array $def): bool
    {
        return $metric->getName() !== $def['name'] || $metric->getUnit() !== $def['unit']
            || $metric->getDirection() !== $def['direction'] || $metric->getTimeAgg() !== $def['timeAgg']
            || $metric->getGranularity() !== $def['granularity'] || $metric->getFormula() !== $def['formula']
            || $metric->getPlanFormula() !== $def['planFormula'] || $metric->isShowDailyAvg() !== $def['dailyAvg']
            || $metric->getSourceType() !== $def['source'] || $metric->getSourceConfig() != $def['sourceConfig']
            || $metric->getWhatMeasured() !== ($def['card'][0] ?? null) || $metric->getFormulaText() !== ($def['card'][1] ?? null);
    }

    /**
     * Platform and Bitrix metrics need a calculator config, validated by the calculator's own parser; manual and
     * push metrics take none. A formula takes none either: its source only tells where its components come from.
     *
     * @return array{MetricSourceType, ?array<string, mixed>}
     */
    private function source(string $code, mixed $source, mixed $config, bool $isFormula): array
    {
        $type = MetricSourceType::tryFrom(\is_string($source) ? $source : '')
            ?? throw new \InvalidArgumentException(\sprintf('%s: unknown source "%s".', $code, \is_scalar($source) ? $source : get_debug_type($source)));
        if (null !== $config && !\is_array($config)) {
            throw new \InvalidArgumentException(\sprintf('%s: source_config must be a map.', $code));
        }
        /* @var array<string, mixed>|null $config */
        if ($isFormula) {
            if (null !== $config) {
                throw new \InvalidArgumentException(\sprintf('%s: a formula takes no source_config.', $code));
            }

            return [$type, null];
        }
        $computed = \in_array($type, [MetricSourceType::PlatformApi, MetricSourceType::Bitrix], true);
        if ($computed !== (null !== $config)) {
            throw new \InvalidArgumentException(\sprintf('%s: source_config goes with source platform_api or bitrix only, and they need one.', $code));
        }

        try {
            $parsed = match ($type) {
                MetricSourceType::PlatformApi => PlatformMetricConfig::fromArray($config),
                MetricSourceType::Bitrix => BitrixMetricConfig::fromArray($config),
                default => true,
            };
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(\sprintf('%s: %s', $code, $e->getMessage()), 0, $e);
        }
        if (null === $parsed) {
            throw new \InvalidArgumentException(\sprintf('%s: source_config needs a "calculator".', $code));
        }

        return [$type, $config];
    }
}
