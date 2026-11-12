<?php

declare(strict_types=1);

namespace App\Integration\Raw;

/**
 * Defensive conversion of external API values into raw_* columns.
 * Anything malformed becomes NULL instead of breaking the whole sync.
 */
final class RawValue
{
    /** Business timezone: all period boundaries are Madrid wall time. */
    public const string TIMEZONE = 'Europe/Madrid';

    /**
     * @param array<string, mixed> $item
     */
    public static function string(array $item, string $key, int $maxLength = 255): ?string
    {
        $value = $item[$key] ?? null;
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value ? null : mb_substr($value, 0, $maxLength);
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function int(array $item, string $key): ?int
    {
        $value = $item[$key] ?? null;

        return \is_int($value) ? $value : (\is_string($value) && ctype_digit($value) ? (int) $value : null);
    }

    /**
     * Decimal as a string for a DECIMAL column, e.g. "1234.50".
     *
     * @param array<string, mixed> $item
     */
    public static function decimal(array $item, string $key): ?string
    {
        $value = $item[$key] ?? null;
        if (\is_int($value)) {
            return (string) $value;
        }

        return \is_string($value) && 1 === preg_match('/^-?\d{1,12}(\.\d+)?$/', trim($value)) ? trim($value) : null;
    }

    /**
     * ISO 8601 (any offset) → "Y-m-d H:i:s" in Madrid time.
     *
     * @param array<string, mixed> $item
     */
    public static function datetime(array $item, string $key): ?string
    {
        return self::parse($item[$key] ?? null)?->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function date(array $item, string $key): ?string
    {
        return self::parse($item[$key] ?? null)?->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function json(array $item): string
    {
        return json_encode($item, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function now(): string
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE))->format('Y-m-d H:i:s');
    }

    private static function parse(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone(self::TIMEZONE))
                ->setTimezone(new \DateTimeZone(self::TIMEZONE));
        } catch (\Exception) {
            return null;
        }
    }
}
