<?php

declare(strict_types=1);

namespace App\Tests\Support\Ai;

use App\Service\Ai\Llm\AiUnavailableException;
use App\Service\Ai\Llm\LlmClient;
use App\Service\Ai\Llm\LlmReply;
use App\Service\Ai\Llm\LlmToolCall;

/**
 * Scripted stand-in for the language model (test env and the e2e stand): no network, no cost.
 * Tests queue replies with reply()/toolCall() and read what was sent from $requests.
 * Without queued replies it answers with a fixed text that quotes the start of the last user message,
 * so the e2e tests can check the UI end to end.
 */
final class FakeLlmClient implements LlmClient
{
    public const string MODEL = 'fake-model';

    public bool $configured = true;

    /** @var list<array{system: string, messages: list<array<string, mixed>>, tools: list<array<string, mixed>>, effort: string, jsonSchema: array<string, mixed>|null}> */
    public array $requests = [];

    /** @var list<LlmReply|\Throwable> */
    private array $queue = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(string $system, array $messages, array $tools = [], string $effort = 'medium', int $maxTokens = 8000, ?array $jsonSchema = null): LlmReply
    {
        if (!$this->configured) {
            throw AiUnavailableException::notConfigured();
        }
        $this->requests[] = ['system' => $system, 'messages' => $messages, 'tools' => $tools, 'effort' => $effort, 'jsonSchema' => $jsonSchema];

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if (null !== $next) {
            return $next;
        }

        // Default answers: a JSON object with test strings for structured requests, a quoting text otherwise.
        if (null !== $jsonSchema) {
            $fields = array_keys($jsonSchema['properties'] ?? []);

            return $this->text(json_encode(array_fill_keys($fields, 'Тестовый черновик ИИ'), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        }

        return $this->text('Тестовый ответ ИИ. '.mb_substr($this->lastUserText($messages), 0, 80));
    }

    public function reply(string $text): self
    {
        $this->queue[] = $this->text($text);

        return $this;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function toolCall(string $name, array $input, string $id = 'toolu_test'): self
    {
        $block = ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input];
        $this->queue[] = new LlmReply('tool_use', '', [new LlmToolCall($id, $name, $input)], [$block], self::MODEL, 100, 20);

        return $this;
    }

    public function fail(\Throwable $error): self
    {
        $this->queue[] = $error;

        return $this;
    }

    private function text(string $text): LlmReply
    {
        return new LlmReply('end_turn', $text, [], [['type' => 'text', 'text' => $text]], self::MODEL, 100, 50);
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function lastUserText(array $messages): string
    {
        for ($i = \count($messages) - 1; $i >= 0; --$i) {
            if ('user' === ($messages[$i]['role'] ?? null) && \is_string($messages[$i]['content'] ?? null)) {
                return $messages[$i]['content'];
            }
        }

        return '';
    }
}
