<?php

declare(strict_types=1);

namespace App\Security\Ingest;

use App\Entity\Sync\IngestToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The authenticated Push API source: a token, not a person. It has no org membership,
 * only ROLE_INGEST, so it can reach /api/ingest/* and nothing else.
 */
final readonly class IngestClient implements UserInterface
{
    public const string ROLE = 'ROLE_INGEST';

    public function __construct(public IngestToken $token)
    {
    }

    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'ingest:'.$this->token->getName();
    }
}
