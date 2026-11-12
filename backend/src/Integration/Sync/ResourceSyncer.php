<?php

declare(strict_types=1);

namespace App\Integration\Sync;

/**
 * Copies one resource of an external source into its raw_* table.
 */
interface ResourceSyncer
{
    /** Stable name, used as sync_run.scope and as the --resource option value. */
    public static function resource(): string;

    /**
     * @param \DateTimeImmutable|null $since start of the last successful run; null = full sync
     *
     * @return int rows written
     */
    public function sync(?\DateTimeImmutable $since): int;

    /**
     * The raw_* table a full sync rewrites completely: rows it did not touch are gone from the source.
     */
    public static function rawTable(): string;
}
