<?php

declare(strict_types=1);

namespace App\DTO\Rnp;

use App\DTO\UserRefDto;
use App\Entity\Metric\MetricDeviationNote;

final readonly class DeviationNoteDto
{
    public function __construct(
        public string $periodType,
        public string $periodStart,
        public string $reason,
        public ?string $action,
        public ?UserRefDto $author,
        public string $updatedAt,
    ) {
    }

    public static function fromEntity(MetricDeviationNote $note): self
    {
        return new self(
            $note->getPeriodType()->value,
            $note->getPeriodStart()->format('Y-m-d'),
            $note->getReason(),
            $note->getAction(),
            UserRefDto::fromNullable($note->getAuthor()),
            $note->getUpdatedAt()->format(\DATE_ATOM),
        );
    }
}
