<?php

declare(strict_types=1);

namespace App\Service\Ai\Llm;

/**
 * One request to the language model. The production implementation is ClaudeLlmClient (Anthropic SDK);
 * tests replace it with a scripted fake, so no test ever calls the real API.
 */
interface LlmClient
{
    /** False when no API key is configured: AI features are then reported as unavailable. */
    public function isConfigured(): bool;

    /**
     * @param string                     $system     stable instructions (cached by the API between requests)
     * @param list<array<string, mixed>> $messages   conversation; assistant turns carry LlmReply::$assistantContent as is
     * @param list<array<string, mixed>> $tools      tool definitions (name, description, inputSchema)
     * @param string                     $effort     low | medium | high
     * @param array<string, mixed>|null  $jsonSchema structured output: the reply text is JSON valid against this schema
     *
     * @throws AiUnavailableException when the API cannot answer (no key, network, overload, refusal)
     */
    public function send(string $system, array $messages, array $tools = [], string $effort = 'medium', int $maxTokens = 8000, ?array $jsonSchema = null): LlmReply;
}
