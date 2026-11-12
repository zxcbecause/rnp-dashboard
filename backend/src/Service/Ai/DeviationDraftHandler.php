<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\DTO\Ai\DeviationDraftDto;
use App\DTO\MetricCardDto;
use App\Entity\Auth\User;
use App\Entity\Metric\MetricDeviationNote;
use App\Enum\PeriodType;
use App\Security\Access\AccessScopeResolver;
use App\Service\Metric\DeviationNoteHandler;
use App\Service\Metric\MetricEditPolicy;
use App\Service\Metric\MetricSeriesHandler;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * AI draft of a deviation explanation (feature B): the model reads the metric's history, the department's week
 * and earlier explanations, and proposes "reason" + "action" for the head to edit and save.
 */
final readonly class DeviationDraftHandler
{
    private const string SYSTEM = <<<'TXT'
        Ты помогаешь руководителю отдела компании Acme (управление арендой апартаментов в Испании) объяснить отклонение
        метрики от плана по методике «Рука на пульсе»: почему так вышло и что с этим делаем.

        Правила:
        - Пиши по-русски, коротко и по делу, от лица отдела («мы»).
        - reason: 1–3 предложения. Опирайся только на данные: динамику по неделям, тот же период год назад, другие
          метрики отдела за ту же неделю (например, если упали брони — какие порталы или разрезы просели), прошлые
          объяснения. Цифры пиши как «72 %», «12 400 €». Если причина — предположение, так и скажи («вероятно…»)
          и назови, что проверить. Не выдумывай внешних причин, которых нет в данных.
        - action: 1–2 конкретных шага с понятным результатом (что, кто/какой отдел, к какому сроку, если уместно).
          Если отклонение в лучшую сторону или не требует действий — коротко скажи, что закрепить или за чем следить.
        - Если плана нет или факт не внесён, прямо скажи об этом в reason и предложи в action задать план / внести факт.
        TXT;

    /** @var array<string, mixed> */
    private const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'reason' => ['type' => 'string', 'description' => 'Почему метрика отклонилась от плана (1–3 предложения)'],
            'action' => ['type' => 'string', 'description' => 'Что делаем (1–2 конкретных шага)'],
        ],
        'required' => ['reason', 'action'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private AiGateway $gateway,
        private AiContextBuilder $context,
        private DeviationNoteHandler $notes,
        private MetricSeriesHandler $series,
        private AccessScopeResolver $scopes,
    ) {
    }

    public function draft(int $metricId, string $periodType, string $periodStart, User $user): DeviationDraftDto
    {
        $metric = $this->notes->visibleMetric($metricId);
        if (!MetricEditPolicy::canExplainDeviation($metric, $this->scopes->resolve($user), $user)) {
            throw new AccessDeniedHttpException('Объяснять отклонения могут руководитель, сотрудники отдела и ответственный за метрику.');
        }
        [$type, $start] = $this->notes->period($periodType, $periodStart);

        $week = $this->context->week((int) $metric->getOrgUnit()->getId(), $start->format('Y-m-d'), $user);
        $history = PeriodType::Week === $type
            ? $this->series->handle($metricId, 'week', $start->modify('-12 weeks')->format('Y-m-d'), $start->format('Y-m-d'), compareYear: true)
            : $this->series->handle($metricId, 'month', $start->modify('-11 months')->format('Y-m-d'), $start->format('Y-m-d'), compareYear: true);
        $card = MetricCardDto::fromEntity($metric);

        $this->gateway->admit($user);
        set_time_limit(180);

        $payload = [
            'metric' => [
                'id' => $metricId, 'name' => $metric->getName(), 'unit' => $metric->getUnit()->value,
                'direction' => $metric->getDirection()->value, 'whatMeasured' => $card->whatMeasured,
                'formula' => $card->formulaText, 'source' => $card->sourceText, 'planFormula' => $metric->getPlanFormula(),
            ],
            'period' => ['type' => $type->value, 'start' => $start->format('Y-m-d')],
            'history' => $history,
            'department' => $week['context'],
            'earlierExplanations' => array_map(static fn (MetricDeviationNote $n): array => [
                'period' => $n->getPeriodStart()->format('Y-m-d'), 'reason' => $n->getReason(), 'action' => $n->getAction(),
            ], $this->notes->history($metric, $start)),
        ];

        $reply = $this->gateway->send($user, 'deviation_draft', self::SYSTEM, [[
            'role' => 'user',
            'content' => AiContextBuilder::VALUE_NOTES."\n\nОбъясни отклонение метрики «{$metric->getName()}» за период "
                .$start->format('d.m.Y').' (history — значения по периодам, factPrevYear — год назад; department — неделя отдела).'
                ."\n\nДанные (JSON):\n".json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        ]], effort: 'medium', maxTokens: 4000, jsonSchema: self::SCHEMA);

        $data = json_decode($reply->text, true);
        if (!\is_array($data) || !\is_string($data['reason'] ?? null) || !\is_string($data['action'] ?? null)) {
            throw new UnprocessableEntityHttpException('ИИ вернул ответ в неожиданном формате. Попробуйте ещё раз.');
        }

        return new DeviationDraftDto(trim($data['reason']), trim($data['action']));
    }
}
