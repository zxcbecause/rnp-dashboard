<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\DTO\Ai\AiWeeklyReviewDto;
use App\Entity\Ai\AiWeeklyReview;
use App\Entity\Auth\User;
use App\Entity\Org\OrgUnit;
use App\Service\Ai\Llm\AiUnavailableException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * AI review of a unit's RNP week (feature A): what is red, what drags the month down, what to look at.
 * The review is stored per unit and week; generating again replaces it.
 */
final readonly class WeeklyReviewHandler
{
    private const string SYSTEM = <<<'TXT'
        Ты — аналитик управленческих метрик компании Acme (управление арендой апартаментов в Испании). Компания ведёт
        метрики по методике «Рука на пульсе» (РНП): у каждой метрики есть план и факт за неделю и месяц, цвет выполнения
        и прогноз месяца (run rate).

        Задача: короткий разбор недели отдела для его руководителя перед планёркой.

        Правила:
        - Пиши по-русски, деловым языком, без вступлений и воды. Обращайся к руководителю на «вы» без приветствий.
        - Используй только цифры из данных. Ничего не придумывай; если данных не хватает (нет плана, факт не внесён) —
          так и скажи одной фразой.
        - Форматирование: Markdown, только маркированные списки и **жирный** для названий метрик. Без заголовков и таблиц.
        - Объём: 4–7 пунктов, затем строка «**Фокус недели:**» и 1–3 конкретных действия.
        - Порядок: сначала то, что не выполнено (red) или под риском (neutral) и сильнее всего влияет на месяц (run rate ниже
          плана), затем заметные изменения к прошлой неделе, затем просроченные действия и невнесённые факты.
        - Проценты пиши как «72 %», деньги как «12 400 €», округляй разумно.
        - Если сегмент недели короче 7 дней или неделя ещё идёт, учитывай это при сравнении с прошлой неделей.
        - Не давай общих советов вроде «усилить работу» — только то, что следует из данных.
        TXT;

    public function __construct(
        private AiGateway $gateway,
        private AiContextBuilder $context,
        private EntityManagerInterface $em,
    ) {
    }

    public function get(int $unitId, ?string $week, User $user): AiWeeklyReviewDto
    {
        $built = $this->context->week($unitId, $week, $user);
        $weekStart = $built['week']->weekStart;

        return AiWeeklyReviewDto::from($this->gateway->isAvailable(), $weekStart, $this->find($unitId, $weekStart));
    }

    public function generate(int $unitId, ?string $week, User $user): AiWeeklyReviewDto
    {
        $built = $this->context->week($unitId, $week, $user);
        $this->gateway->admit($user);
        set_time_limit(180);

        $reply = $this->gateway->send($user, 'weekly_review', self::SYSTEM, [[
            'role' => 'user',
            'content' => AiContextBuilder::VALUE_NOTES."\n\nДанные недели (JSON):\n"
                .json_encode($built['context'], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        ]], effort: 'medium', maxTokens: 6000);

        if (!$reply->isComplete()) {
            // Never replace a stored review with an empty or cut one.
            throw new AiUnavailableException('ИИ не успел сформулировать разбор. Попробуйте ещё раз.');
        }

        $weekStart = $built['week']->weekStart;
        $review = $this->find($unitId, $weekStart);
        if (null === $review) {
            $unit = $this->em->find(OrgUnit::class, $unitId) ?? throw new \LogicException('Unit vanished while generating the review.');
            $review = new AiWeeklyReview($unit, new \DateTimeImmutable($weekStart), $reply->text, $reply->model, $user);
            $this->em->persist($review);
        } else {
            $review->replace($reply->text, $reply->model, $user);
        }
        $this->em->flush();

        return AiWeeklyReviewDto::from(true, $weekStart, $review);
    }

    private function find(int $unitId, string $weekStart): ?AiWeeklyReview
    {
        return $this->em->getRepository(AiWeeklyReview::class)->findOneBy([
            'orgUnit' => $unitId,
            'weekStart' => new \DateTimeImmutable($weekStart),
        ]);
    }
}
