<?php

declare(strict_types=1);

namespace App\DTO\Ai;

/** AI proposal for a deviation note: shown in the form, saved only by the user. */
final readonly class DeviationDraftDto
{
    public function __construct(
        public string $reason,
        public string $action,
    ) {
    }
}
