<?php

declare(strict_types=1);

namespace App\Integration\Platform;

/**
 * Failure talking to the Acme platform API. Messages never contain credentials or tokens.
 */
final class PlatformApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $statusCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
