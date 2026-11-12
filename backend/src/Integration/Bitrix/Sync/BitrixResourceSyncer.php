<?php

declare(strict_types=1);

namespace App\Integration\Bitrix\Sync;

use App\Integration\Sync\ResourceSyncer;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Copies one Bitrix24 CRM resource into its raw_bitrix_* table.
 */
#[AutoconfigureTag(self::TAG)]
interface BitrixResourceSyncer extends ResourceSyncer
{
    public const string TAG = 'app.bitrix_resource_syncer';

    /** Overlap of incremental runs against clock skew between the portal and us. */
    public const string OVERLAP = '-1 hour';

    /** Owner acquisition pipelines: 4 "Propietario", 20 "Online Managmant". */
    public const array OWNER_PIPELINES = [4, 20];
}
