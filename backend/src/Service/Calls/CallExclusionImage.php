<?php

declare(strict_types=1);

namespace App\Service\Calls;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Screenshot of a call exclusion request as it comes from the API: base64 inside the JSON body (the frontend proxy
 * is JSON-only). The type is taken from the content itself, never from what the client says.
 */
final readonly class CallExclusionImage
{
    public const int MAX_BYTES = 4 * 1024 * 1024;

    private function __construct(
        public string $contentType,
        public string $content,
    ) {
    }

    public static function fromBase64(string $base64): self
    {
        $content = base64_decode($base64, true);
        if (false === $content || '' === $content) {
            throw new UnprocessableEntityHttpException('Картинка повреждена: её не удалось прочитать.');
        }
        if (\strlen($content) > self::MAX_BYTES) {
            throw new UnprocessableEntityHttpException('Картинка больше 4 МБ.');
        }

        $type = match (true) {
            str_starts_with($content, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($content, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($content, 'RIFF') && 'WEBP' === substr($content, 8, 4) => 'image/webp',
            default => throw new UnprocessableEntityHttpException('Приложить можно картинку PNG, JPG или WebP.'),
        };

        return new self($type, $content);
    }
}
