<?php

declare(strict_types=1);

namespace App\Service\Ai\Llm;

final readonly class LlmReply
{
    /**
     * @param list<LlmToolCall> $toolCalls
     * @param list<mixed>       $assistantContent content blocks exactly as returned (thinking blocks included): they are
     *                                            appended to the next request unchanged, as the API requires
     */
    public function __construct(
        public string $stopReason,
        public string $text,
        public array $toolCalls,
        public array $assistantContent,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
    ) {
    }

    /**
     * A usable final text: not empty and not cut by the output limit (thinking shares that budget).
     */
    public function isComplete(): bool
    {
        return '' !== trim($this->text) && 'max_tokens' !== $this->stopReason;
    }

    public function wantsTools(): bool
    {
        return 'tool_use' === $this->stopReason && [] !== $this->toolCalls;
    }
}
