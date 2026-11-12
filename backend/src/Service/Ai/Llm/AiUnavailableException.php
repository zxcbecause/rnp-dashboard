<?php

declare(strict_types=1);

namespace App\Service\Ai\Llm;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The model could not answer. Mapped to 503 with a message for the user; details go to the log only
 * (they may contain request ids, never the API key).
 */
final class AiUnavailableException extends HttpException
{
    public function __construct(string $message = 'ИИ сейчас недоступен, попробуйте позже.', ?\Throwable $previous = null)
    {
        parent::__construct(503, $message, $previous);
    }

    public static function notConfigured(): self
    {
        return new self('ИИ не настроен: нет ключа ANTHROPIC_API_KEY.');
    }
}
