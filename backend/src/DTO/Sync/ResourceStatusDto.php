<?php

declare(strict_types=1);

namespace App\DTO\Sync;

/**
 * Last runs of one resource of a source. error is shown to admins only (it may hold internal details).
 */
final readonly class ResourceStatusDto
{
    public function __construct(
        public string $resource,
        public ?string $lastRunAt,
        public ?string $lastStatus,
        public ?string $lastSuccessAt,
        public ?string $error,
    ) {
    }
}
