<?php

declare(strict_types=1);

namespace App\Integration\Platform\Sync;

use App\Integration\Sync\ResourceSyncer;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Copies one platform list endpoint into its raw_platform_* table.
 */
#[AutoconfigureTag(self::TAG)]
interface PlatformResourceSyncer extends ResourceSyncer
{
    public const string TAG = 'app.platform_resource_syncer';
}
