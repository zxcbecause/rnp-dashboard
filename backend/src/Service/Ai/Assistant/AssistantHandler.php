<?php

declare(strict_types=1);

namespace App\Service\Ai\Assistant;

use App\DTO\Ai\AssistantReplyDto;
use App\Entity\Auth\User;
use App\Service\Ai\AiGateway;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * AI assistant chat (features C + E): answers questions about the numbers by calling read-only tools on the
 * user's behalf, and questions about the dashboard itself from the built-in guide. Stateless: the client sends
 * the visible conversation (text only) with every question.
 */
final readonly class AssistantHandler
{
    /** Model calls per question: enough for "find the unit → its week → a metric's history". */
    private const int MAX_STEPS = 8;
    private const int MAX_TURNS = 20;
    private const int MAX_CHARS = 4000;
    /** Earlier answers are sent back as context: a long one is cut, never refused. */
    private const int MAX_ANSWER_CHARS = 6000;
    /** Cost bound of one question: tool calls per step and characters of tool results in total. */
    private const int MAX_CALLS_PER_STEP = 6;
    private const int MAX_RESULT_CHARS_TOTAL = 150000;

    public function __construct(
        private AiGateway $gateway,
        private AssistantTools $tools,
    ) {
    }

    /**
     * @param list<array{role: string, content: string}> $history ends with the user's question
     * @param string|null                                $page    where the user is ("/rnp-week?unit=52"), for "this week"
     */
    public function ask(array $history, ?string $page, User $user, ?\DateTimeImmutable $now = null): AssistantReplyDto
    {
        $messages = $this->validate($history);
        $this->gateway->admit($user);
        set_time_limit(180);

        // Volatile context goes into the last question, never into the cached system prompt.
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid'));
        $last = \count($messages) - 1;
        $messages[$last]['content'] = \sprintf(
            "[Сегодня %s, %s (Мадрид). Пользователь: %s.%s]\n\n%s",
            $now->format('Y-m-d'),
            ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'][(int) $now->format('w')],
            $user->getName(),
            null === $page || '' === $page ? '' : ' Открыта страница: '.mb_substr($page, 0, 200).'.',
            $messages[$last]['content'],
        );

        $tools = $this->tools->definitions();
        $used = [];
        $resultChars = 0;
        for ($step = 0; $step < self::MAX_STEPS; ++$step) {
            $reply = $this->gateway->send($user, 'chat', AssistantGuide::SYSTEM, $messages, $tools, effort: 'medium', maxTokens: 8000);
            if (!$reply->wantsTools()) {
                return new AssistantReplyDto('' === $reply->text ? 'Не получилось сформулировать ответ. Попробуйте переформулировать вопрос.' : $reply->text, array_values(array_unique($used)));
            }

            $results = [];
            foreach ($reply->toolCalls as $index => $call) {
                if ($index >= self::MAX_CALLS_PER_STEP || $resultChars >= self::MAX_RESULT_CHARS_TOTAL) {
                    // Every tool call needs a result; past the budget it is a refusal, not data.
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $call->id, 'content' => 'Лимит данных на один вопрос исчерпан: ответь по уже полученным данным или попроси пользователя сузить вопрос.', 'isError' => true];
                    continue;
                }
                [$content, $isError] = $this->tools->run($call->name, $call->input, $user);
                $resultChars += mb_strlen($content);
                $results[] = ['type' => 'tool_result', 'toolUseID' => $call->id, 'content' => $content, 'isError' => $isError];
                $used[] = AssistantTools::label($call->name, $call->input);
            }
            if (self::MAX_STEPS - 2 === $step) {
                $results[] = ['type' => 'text', 'text' => 'Лимит обращений к данным почти исчерпан: ответь по уже полученным данным.'];
            }

            $messages[] = ['role' => 'assistant', 'content' => $reply->assistantContent];
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        return new AssistantReplyDto('Вопрос потребовал слишком много запросов к данным. Уточните отдел, метрику или период.', array_values(array_unique($used)));
    }

    /**
     * @param list<array{role: string, content: string}> $history
     *
     * @return list<array{role: string, content: string}>
     */
    private function validate(array $history): array
    {
        $history = \array_slice($history, -self::MAX_TURNS);
        if ([] === $history) {
            throw new UnprocessableEntityHttpException('Задайте вопрос.');
        }

        $messages = [];
        foreach ($history as $message) {
            $role = $message['role'];
            $content = trim($message['content']);
            if (!\in_array($role, ['user', 'assistant'], true) || '' === $content) {
                throw new UnprocessableEntityHttpException('Неверный формат переписки.');
            }
            if ('user' === $role && mb_strlen($content) > self::MAX_CHARS) {
                throw new UnprocessableEntityHttpException(\sprintf('Сообщение длиннее %d символов.', self::MAX_CHARS));
            }
            if ('assistant' === $role && mb_strlen($content) > self::MAX_ANSWER_CHARS) {
                $content = mb_substr($content, 0, self::MAX_ANSWER_CHARS).'…';
            }
            // The API needs alternating turns starting with the user; a trimmed history may start with an answer.
            if ([] === $messages && 'assistant' === $role) {
                continue;
            }
            if ([] !== $messages && $messages[\count($messages) - 1]['role'] === $role) {
                throw new UnprocessableEntityHttpException('Неверный формат переписки.');
            }
            $messages[] = ['role' => $role, 'content' => $content];
        }

        if ([] === $messages || 'user' !== $messages[\count($messages) - 1]['role']) {
            throw new UnprocessableEntityHttpException('Последним должен быть вопрос пользователя.');
        }

        return $messages;
    }
}
