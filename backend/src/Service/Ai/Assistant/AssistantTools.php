<?php

declare(strict_types=1);

namespace App\Service\Ai\Assistant;

use App\DTO\ErrorLog\ErrorEntryDto;
use App\DTO\MetricDto;
use App\DTO\OrgUnitDto;
use App\Entity\Auth\User;
use App\Repository\Metric\MetricRepository;
use App\Service\Ai\AiContextBuilder;
use App\Service\ErrorLog\ErrorLogHandler;
use App\Service\Metric\MetricFindHandler;
use App\Service\Metric\MetricSeriesHandler;
use App\Service\Org\OrgUnitListHandler;
use App\Service\Sync\DataStatusHandler;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Read-only tools of the AI assistant. Every call goes through the regular handlers with the current user's
 * access scope, so the model can only read what the user could open on the screens; foreign or missing ids
 * come back as "not found". Arguments come from the model: each one is validated before use.
 */
final readonly class AssistantTools
{
    /** Tool results longer than this are cut: a reply never needs a whole history dump. */
    private const int MAX_RESULT_CHARS = 40000;

    public function __construct(
        private OrgUnitListHandler $units,
        private MetricRepository $metrics,
        private MetricFindHandler $metricCards,
        private MetricSeriesHandler $series,
        private AiContextBuilder $context,
        private ErrorLogHandler $errors,
        private DataStatusHandler $dataStatus,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $date = ['type' => 'string', 'description' => 'Дата Y-m-d'];

        return [
            self::tool('list_units', 'Отделы (узлы оргструктуры), доступные пользователю: id, название, родитель, руководитель. Начинай с него, если не знаешь id отдела.', []),
            self::tool('find_metrics', 'Метрики доступных отделов: id, код, название, отдел, единица, источник. Фильтр по подстроке названия или кода.', [
                'query' => ['type' => 'string', 'description' => 'Подстрока названия или кода; пусто — все метрики'],
            ]),
            self::tool('get_rnp_week', 'РНП-неделя отдела: все метрики с планом/фактом/% за неделю и месяц, run rate, фактом прошлой недели, разбором отклонений; Execution rate и невыполненные действия.', [
                'unit_id' => ['type' => 'integer', 'description' => 'id отдела'],
                'week' => $date + ['description' => 'Любой день нужной недели (Y-m-d); по умолчанию — текущая'],
            ], ['unit_id']),
            self::tool('get_metric_series', 'Динамика одной метрики по неделям или месяцам: план, факт, % и (по желанию) факт год назад. Диапазон до 24 месяцев.', [
                'metric_id' => ['type' => 'integer'],
                'period' => ['type' => 'string', 'enum' => ['week', 'month']],
                'from' => $date,
                'to' => $date,
                'compare_year' => ['type' => 'boolean', 'description' => 'Добавить факт того же периода год назад'],
            ], ['metric_id', 'period']),
            self::tool('get_metric_card', 'Логика метрики: что измеряется, формула, источник, период, где используется, направление, ответственные.', [
                'metric_id' => ['type' => 'integer'],
            ], ['metric_id']),
            self::tool('get_error_log', 'Журнал значимых ошибок отдела (и его ветки) за период: описание, последствия, ущерб, причина, статус.', [
                'unit_id' => ['type' => 'integer'],
                'from' => $date,
                'to' => $date,
            ], ['unit_id']),
            self::tool('get_data_status', 'Свежесть данных интеграций (платформа Acme, Битрикс24): когда последний раз обновлялись, есть ли сбои.', []),
        ];
    }

    /**
     * Runs one tool call. Returns [result JSON, is error].
     *
     * @param array<string, mixed> $input
     *
     * @return array{string, bool}
     */
    public function run(string $name, array $input, User $user): array
    {
        try {
            $result = match ($name) {
                'list_units' => array_map(static fn (OrgUnitDto $u): array => [
                    'id' => $u->id, 'parentId' => $u->parentId, 'name' => $u->name, 'head' => $u->headUser?->name,
                ], $this->units->handle()),
                'find_metrics' => $this->findMetrics(self::string($input, 'query') ?? ''),
                'get_rnp_week' => $this->context->week(self::int($input, 'unit_id'), self::date($input, 'week'), $user)['context'],
                'get_metric_series' => $this->series->handle(
                    self::int($input, 'metric_id'),
                    \in_array($input['period'] ?? null, ['week', 'month'], true) ? (string) $input['period'] : 'week',
                    self::date($input, 'from'),
                    self::date($input, 'to'),
                    compareYear: true === ($input['compare_year'] ?? false),
                ),
                'get_metric_card' => $this->card($this->metricCards->handle(self::int($input, 'metric_id'))),
                'get_error_log' => $this->errorLog(self::int($input, 'unit_id'), self::date($input, 'from'), self::date($input, 'to'), $user),
                'get_data_status' => $this->dataStatus->handle(false),
                default => throw new \InvalidArgumentException(\sprintf('Неизвестный инструмент %s.', $name)),
            };
        } catch (HttpExceptionInterface $e) {
            return [404 === $e->getStatusCode() ? 'Не найдено или нет доступа у пользователя.' : $e->getMessage(), true];
        } catch (\InvalidArgumentException $e) {
            return [$e->getMessage(), true];
        }

        $json = json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return [mb_strlen($json) > self::MAX_RESULT_CHARS ? mb_substr($json, 0, self::MAX_RESULT_CHARS).' …[обрезано]' : $json, false];
    }

    /**
     * Human label of a call for the UI ("looked at: …").
     *
     * @param array<string, mixed> $input
     */
    public static function label(string $name, array $input): string
    {
        return match ($name) {
            'list_units' => 'оргструктура',
            'find_metrics' => 'поиск метрик',
            'get_rnp_week' => 'РНП-неделя',
            'get_metric_series' => 'динамика метрики',
            'get_metric_card' => 'логика метрики',
            'get_error_log' => 'журнал ошибок',
            'get_data_status' => 'статус данных',
            default => $name,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findMetrics(string $query): array
    {
        $units = [];
        foreach ($this->units->handle() as $unit) {
            $units[$unit->id] = $unit->name;
        }
        $needle = mb_strtolower(trim($query));

        $found = [];
        foreach ($this->metrics->findActiveByOrgUnits(array_keys($units)) as $metric) {
            if ('' !== $needle && !str_contains(mb_strtolower($metric->getName().' '.$metric->getCode()), $needle)) {
                continue;
            }
            $found[] = [
                'id' => $metric->getId(), 'code' => $metric->getCode(), 'name' => $metric->getName(),
                'unit' => $units[(int) $metric->getOrgUnit()->getId()] ?? null, 'valueUnit' => $metric->getUnit()->value,
                'source' => null !== $metric->getFormula() ? 'formula' : $metric->getSourceType()->value,
                'granularity' => $metric->getGranularity()->value,
            ];
        }

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    private function card(MetricDto $metric): array
    {
        return [
            'id' => $metric->id, 'name' => $metric->name, 'unit' => $metric->unit, 'direction' => $metric->direction,
            'granularity' => $metric->granularity, 'source' => $metric->sourceType, 'formula' => $metric->formula,
            'owner' => $metric->owner?->name, 'filler' => $metric->filler?->name, 'card' => $metric->card,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorLog(int $unitId, ?string $from, ?string $to, User $user): array
    {
        $log = $this->errors->list($unitId, $from, $to, $user);

        return [
            'from' => $log->from, 'to' => $log->to, 'total' => $log->total, 'open' => $log->open,
            'byImpact' => $log->byImpact, 'moneyTotalEur' => $log->moneyTotal,
            'entries' => array_map(static fn (ErrorEntryDto $e): array => [
                'date' => $e->occurredOn, 'unit' => $e->orgUnitName, 'description' => $e->description,
                'impact' => $e->impactType, 'amountEur' => $e->impactAmount, 'cause' => $e->cause,
                'prevention' => $e->prevention, 'status' => $e->status,
            ], \array_slice($log->entries, 0, 50)),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string>                        $required
     *
     * @return array<string, mixed>
     */
    private static function tool(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function int(array $input, string $key): int
    {
        $value = $input[$key] ?? null;
        if (\is_int($value) && $value > 0) {
            return $value;
        }
        if (\is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new \InvalidArgumentException(\sprintf('Параметр %s должен быть положительным целым числом.', $key));
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function string(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        return \is_string($value) ? mb_substr($value, 0, 200) : null;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function date(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }
        if (\is_string($value) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        throw new \InvalidArgumentException(\sprintf('Параметр %s должен быть датой Y-m-d.', $key));
    }
}
