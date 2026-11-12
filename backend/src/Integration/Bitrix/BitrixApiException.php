<?php

declare(strict_types=1);

namespace App\Integration\Bitrix;

/**
 * A failed Bitrix24 REST call. The message never contains the webhook URL (it holds the access token).
 */
final class BitrixApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
