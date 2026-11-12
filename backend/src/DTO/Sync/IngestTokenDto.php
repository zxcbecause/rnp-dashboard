<?php

declare(strict_types=1);

namespace App\DTO\Sync;

use App\Entity\Sync\IngestToken;

/**
 * A Push API token as the admin screen shows it: never the token itself, only its first characters.
 */
final readonly class IngestTokenDto
{
    public function __construct(
        public int $id,
        public string $name,
        public int $orgUnitId,
        public string $orgUnitName,
        public string $hint,
        public string $createdAt,
        public ?string $lastUsedAt,
        public ?string $revokedAt,
    ) {
    }

    public static function fromEntity(IngestToken $token): self
    {
        return new self(
            (int) $token->getId(),
            $token->getName(),
            (int) $token->getOrgUnit()->getId(),
            $token->getOrgUnit()->getName(),
            $token->getTokenHint().'…',
            $token->getCreatedAt()->format(\DATE_ATOM),
            $token->getLastUsedAt()?->format(\DATE_ATOM),
            $token->getRevokedAt()?->format(\DATE_ATOM),
        );
    }
}
