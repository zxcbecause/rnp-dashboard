<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\DTO\Ai\AnomalyExplanationDto;
use App\DTO\Rnp\RnpRowDto;
use App\DTO\Rnp\RnpWeekDto;
use App\Entity\Ai\AiAnomalyExplanation;
use App\Entity\Auth\User;
use App\Entity\Metric\Metric;
use App\Entity\Metric\MetricDeviationNote;
use App\Service\Ai\Llm\AiUnavailableException;
use App\Service\Metric\DeviationNoteHandler;
use App\Service\Metric\MetricSeriesHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Lock\LockFactory;

/**
 * AI explanation of a bell alert (feature D): why a metric is red in a week, or why its month forecast (run rate) is
 * behind the plan. Anyone who sees the metric may ask; the text is stored per metric, kind and period and reused
 * while the metric's figures stay the same, so only the first viewer spends a request.
 */
final readonly class AnomalyExplainHandler
{
    private const string SYSTEM = <<<'TXT'
        Ты — аналитик управленческих метрик компании Acme (управление арендой апартаментов в Испании, методика «Рука на
        пульсе»: план и факт по неделям и месяцам). Руководитель увидел алерт по метрике и спрашивает: «почему?».

        Правила:
        - Пиши по-русски, коротко, без вступлений. Markdown: 3–5 пунктов маркированного списка, **жирный** только для
          названий метрик.
        - Первый пункт — что произошло в цифрах (факт, план, % выполнения; для прогноза — прогноз месяца, план, сколько
          нужно в день).
        - Затем 1–3 пункта с вероятными причинами, которые видны в данных: динамика за прошлые недели или месяцы, тот же
          период год назад, связанные метрики отдела за ту же неделю (числитель и знаменатель отношения, разрезы, объём),
          просроченные действия, прошлые объяснения отклонений. Предположение называй предположением («вероятно»).
        - Последний пункт начинается с «Проверить:» — что посмотреть или у кого уточнить, чтобы подтвердить причину.
        - Никаких причин, которых нет в данных, и общих советов. Не составляй план действий — это делает отдел.
        - Если отклонение объясняется данными (короткий сегмент недели, начало месяца, факт не внесён, план по формуле
          или ошибочный план), скажи это первым делом.
        - Проценты пиши как «72 %», деньги как «12 400 €», округляй разумно.
        TXT;

    public function __construct(
        private AiGateway $gateway,
        private AiContextBuilder $context,
        private DeviationNoteHandler $notes,
        private MetricSeriesHandler $series,
        private EntityManagerInterface $em,
        private LockFactory $locks,
    ) {
    }

    public function explain(int $metricId, string $kind, string $date, User $user): AnomalyExplanationDto
    {
        $metric = $this->notes->visibleMetric($metricId);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $day || !\in_array($kind, [AiAnomalyExplanation::KIND_WEEK, AiAnomalyExplanation::KIND_FORECAST], true)) {
            throw new UnprocessableEntityHttpException('kind must be week or forecast, date Y-m-d.');
        }

        $built = $this->context->week((int) $metric->getOrgUnit()->getId(), $day->format('Y-m-d'), $user);
        $week = $built['week'];
        $row = array_values(array_filter($week->rows, static fn (RnpRowDto $r): bool => $r->metricId === $metricId))[0] ?? null;
        $periodStart = new \DateTimeImmutable(AiAnomalyExplanation::KIND_WEEK === $kind ? $week->weekStart : $week->monthStart);
        $figures = null === $row ? null : $this->context->row($row);
        $hash = sha1(json_encode([$kind, $week->weekStart, $week->weekEnd, $figures], \JSON_THROW_ON_ERROR));

        $criteria = ['metric' => $metric, 'kind' => $kind, 'periodStart' => $periodStart];
        $stored = $this->em->getRepository(AiAnomalyExplanation::class)->findOneBy($criteria);
        if (null !== $stored && $stored->getInputHash() === $hash) {
            return AnomalyExplanationDto::from($stored, true);
        }

        // One generation per explanation at a time: a second request (another head, a reopened dialog) waits
        // and gets the stored text instead of paying for the same answer and colliding on the unique key.
        set_time_limit(180);
        $lock = $this->locks->createLock(\sprintf('ai-anomaly-%d-%s-%s', $metricId, $kind, $periodStart->format('Ymd')), 180);
        $lock->acquire(true);
        try {
            return $this->generate($metric, $kind, $day, $periodStart, $hash, $figures, $week, $built['context'], $criteria, $user);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, mixed>|null $figures
     * @param array<string, mixed>      $department
     * @param array<string, mixed>      $criteria
     */
    private function generate(Metric $metric, string $kind, \DateTimeImmutable $day, \DateTimeImmutable $periodStart, string $hash, ?array $figures, RnpWeekDto $week, array $department, array $criteria, User $user): AnomalyExplanationDto
    {
        $stored = $this->em->getRepository(AiAnomalyExplanation::class)->findOneBy($criteria);
        if (null !== $stored) {
            // Possibly written by the request we waited for.
            $this->em->refresh($stored);
            if ($stored->getInputHash() === $hash) {
                return AnomalyExplanationDto::from($stored, true);
            }
        }

        $this->gateway->admit($user);
        $reply = $this->gateway->send($user, 'anomaly', self::SYSTEM, [[
            'role' => 'user',
            'content' => AiContextBuilder::VALUE_NOTES."\n\n".$this->question($metric, $kind, $week->weekStart, $week->weekEnd)
                ."\n\nДанные (JSON):\n".json_encode($this->payload($metric, $kind, $periodStart, $day, $figures, $department), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        ]], effort: 'medium', maxTokens: 3000);
        if (!$reply->isComplete()) {
            // An empty or cut text would be cached for everyone until the figures change.
            throw new AiUnavailableException('ИИ не успел сформулировать объяснение. Попробуйте ещё раз.');
        }

        if (null === $stored) {
            $stored = new AiAnomalyExplanation($metric, $kind, $periodStart, $hash, trim($reply->text), $reply->model, $user);
            $this->em->persist($stored);
        } else {
            $stored->replace($hash, trim($reply->text), $reply->model, $user);
        }
        $this->em->flush();

        return AnomalyExplanationDto::from($stored, false);
    }

    private function question(Metric $metric, string $kind, string $weekStart, string $weekEnd): string
    {
        $period = \sprintf('%s–%s', (new \DateTimeImmutable($weekStart))->format('d.m'), (new \DateTimeImmutable($weekEnd))->format('d.m.Y'));

        return AiAnomalyExplanation::KIND_WEEK === $kind
            ? "Почему метрика «{$metric->getName()}» не выполнила план недели {$period}? (metric — её карточка и значения "
                .'недели, history — по неделям с factPrevYear, department — неделя отдела.)'
            : "Почему прогноз месяца (run rate) метрики «{$metric->getName()}» ниже плана на неделе {$period}? (metric — её "
                .'карточка, значения недели, месяца на дату, runRate и pace; history — по неделям и месяцам с factPrevYear, '
                .'department — неделя отдела.)';
    }

    /**
     * @param array<string, mixed>|null $figures
     * @param array<string, mixed>      $department
     *
     * @return array<string, mixed>
     */
    private function payload(Metric $metric, string $kind, \DateTimeImmutable $periodStart, \DateTimeImmutable $day, ?array $figures, array $department): array
    {
        $id = (int) $metric->getId();
        $history = AiAnomalyExplanation::KIND_WEEK === $kind
            ? ['weeks' => $this->series->handle($id, 'week', $periodStart->modify('-12 weeks')->format('Y-m-d'), $periodStart->format('Y-m-d'), compareYear: true)]
            : [
                'weeks' => $this->series->handle($id, 'week', $day->modify('-8 weeks')->format('Y-m-d'), $day->format('Y-m-d'), compareYear: true),
                'months' => $this->series->handle($id, 'month', $periodStart->modify('-11 months')->format('Y-m-d'), $periodStart->format('Y-m-d'), compareYear: true),
            ];

        return [
            'metric' => [
                'name' => $metric->getName(), 'unit' => $metric->getUnit()->value, 'direction' => $metric->getDirection()->value,
                'whatMeasured' => $metric->getWhatMeasured(), 'formula' => $metric->getFormulaText(), 'source' => $metric->getSourceText(),
                'planFormula' => $metric->getPlanFormula(), 'values' => $figures,
            ],
            'history' => $history,
            'department' => $department,
            'earlierExplanations' => array_map(static fn (MetricDeviationNote $n): array => [
                'period' => $n->getPeriodStart()->format('Y-m-d'), 'reason' => $n->getReason(), 'action' => $n->getAction(),
            ], $this->notes->history($metric, $periodStart->modify('+1 month'))),
        ];
    }
}
