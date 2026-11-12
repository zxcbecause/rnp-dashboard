<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Response of POST /api/ingest/metric-values.
 */
final class IngestResultDto
{
    /** Facts created or changed. */
    public int $written = 0;

    /** Facts already holding the pushed value (or nothing to remove). */
    public int $unchanged = 0;

    /** Facts removed by a null value. */
    public int $removed = 0;
}
