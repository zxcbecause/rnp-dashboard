<?php

declare(strict_types=1);

namespace App\DTO\ErrorLog;

use App\DTO\Rnp\RnpCellDto;
use App\DTO\UserRefDto;
use App\Entity\Journal\ErrorEntry;

final readonly class ErrorEntryDto
{
    public function __construct(
        public int $id,
        public string $occurredOn,
        public ?UserRefDto $reporter,
        public string $description,
        public string $impactType,
        /** EUR, decimal string */
        public ?string $impactAmount,
        public ?string $cause,
        public ?string $prevention,
        public ?UserRefDto $owner,
        public string $status,
        public string $createdAt,
        public bool $canEdit,
        /** Department of the entry: the log of a unit includes its visible descendants */
        public int $orgUnitId,
        public string $orgUnitName,
    ) {
    }

    public static function fromEntity(ErrorEntry $entry, bool $canEdit): self
    {
        return new self(
            (int) $entry->getId(),
            $entry->getOccurredOn()->format('Y-m-d'),
            UserRefDto::fromNullable($entry->getReporter()),
            $entry->getDescription(),
            $entry->getImpactType()->value,
            RnpCellDto::decimal($entry->getImpactAmount(), 2),
            $entry->getCause(),
            $entry->getPrevention(),
            UserRefDto::fromNullable($entry->getOwner()),
            $entry->getStatus()->value,
            $entry->getCreatedAt()->format(\DATE_ATOM),
            $canEdit,
            (int) $entry->getOrgUnit()->getId(),
            $entry->getOrgUnit()->getName(),
        );
    }
}
