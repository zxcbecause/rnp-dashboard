<?php

declare(strict_types=1);

namespace App\Service\Ai\Llm;

use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Claude through the official Anthropic SDK (anthropic-ai/sdk).
 *
 *  - model claude-opus-5-5, adaptive thinking (always on for this model), effort per request;
 *  - the system prompt is cached (cache_control), so repeated calls with the same instructions are cheaper;
 *  - server-side fallbacks ("default") answer when a safety classifier declines on this model;
 *  - the key comes from ANTHROPIC_API_KEY and never appears in messages or logs.
 */
final class ClaudeLlmClient implements LlmClient
{
    public const string MODEL = 'claude-opus-5-5';

    private const string FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private ?Client $client = null;

    public function __construct(
        #[Autowire('%env(default::ANTHROPIC_API_KEY)%')]
        private readonly ?string $apiKey,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->apiKey && '' !== trim($this->apiKey);
    }

    public function send(string $system, array $messages, array $tools = [], string $effort = 'medium', int $maxTokens = 8000, ?array $jsonSchema = null): LlmReply
    {
        if (!$this->isConfigured()) {
            throw AiUnavailableException::notConfigured();
        }

        $outputConfig = ['effort' => $effort];
        if (null !== $jsonSchema) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $jsonSchema];
        }

        try {
            // Messages, tools and output config are plain arrays in the SDK's camelCase shapes (the SDK's own
            // examples do the same); PHPStan cannot match them to the SDK's exhaustive array-shape unions.
            $message = $this->client()->beta->messages->create(
                maxTokens: $maxTokens,
                messages: $messages, // @phpstan-ignore argument.type
                model: self::MODEL,
                fallbacks: 'default',
                outputConfig: $outputConfig, // @phpstan-ignore argument.type
                system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
                tools: [] === $tools ? null : $tools, // @phpstan-ignore argument.type
                betas: [self::FALLBACK_BETA],
                requestOptions: ['timeout' => 120.0, 'maxRetries' => 2],
            );
        } catch (AnthropicException $e) {
            $this->logger->error('Claude request failed: {class} {message}', ['class' => $e::class, 'message' => $e->getMessage()]);

            throw new AiUnavailableException(previous: $e);
        }

        if ('refusal' === $message->stopReason) {
            $this->logger->warning('Claude declined the request: {category}', ['category' => $message->stopDetails->category ?? 'unknown']);

            throw new AiUnavailableException('ИИ не смог ответить на этот запрос. Переформулируйте вопрос.');
        }

        $text = [];
        $calls = [];
        foreach ($message->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $text[] = $block->text;
            } elseif ($block instanceof BetaToolUseBlock) {
                $calls[] = new LlmToolCall($block->id, $block->name, $block->input);
            }
        }

        return new LlmReply(
            (string) $message->stopReason,
            trim(implode("\n\n", $text)),
            $calls,
            $message->content,
            $message->model,
            $message->usage->inputTokens,
            $message->usage->outputTokens,
            $message->usage->cacheReadInputTokens ?? 0,
        );
    }

    private function client(): Client
    {
        return $this->client ??= new Client(apiKey: $this->apiKey, openTelemetry: false);
    }
}
