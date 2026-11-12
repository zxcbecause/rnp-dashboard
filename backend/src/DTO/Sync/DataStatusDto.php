<?php

declare(strict_types=1);

namespace App\DTO\Sync;

/**
 * GET /api/data-status: how fresh the automatic data is.
 */
final readonly class DataStatusDto
{
    /**
     * @param list<SourceStatusDto> $sources
     */
    public function __construct(
        public string $checkedAt,
        public array $sources,
    ) {
    }
}
