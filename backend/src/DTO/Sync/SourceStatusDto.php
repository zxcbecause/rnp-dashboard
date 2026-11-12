<?php

declare(strict_types=1);

namespace App\DTO\Sync;

/**
 * Freshness of one scheduled source (platform_api, bitrix).
 *
 * dataAsOf is the oldest "last success" among its resources: everything is at least that fresh;
 * null when the source is not configured or a resource has never been synced.
 */
final readonly class SourceStatusDto
{
    /**
     * @param list<ResourceStatusDto> $resources
     */
    public function __construct(
        public string $source,
        public bool $configured,
        public ?string $dataAsOf,
        public bool $stale,
        public bool $failing,
        public array $resources,
    ) {
    }
}
