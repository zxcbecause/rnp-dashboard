<?php

declare(strict_types=1);

namespace App\Entity\Sync;

use App\Entity\Org\OrgUnit;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Token of a Push API source (a department head's script). It may write facts of push_api metrics
 * of its org unit and the units below. Only the SHA-256 of the token is stored; the token itself
 * is shown once, when issued (app:ingest-token:create).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ingest_token')]
#[ORM\UniqueConstraint(name: 'uniq_ingest_token_name', columns: ['name'])]
#[ORM\UniqueConstraint(name: 'uniq_ingest_token_hash', columns: ['token_hash'])]
class IngestToken
{
    public const string PREFIX = 'rnp_';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Also the sync_run.scope of its pushes, hence 32 characters. */
    #[ORM\Column(length: 32)]
    private string $name;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OrgUnit $orgUnit;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    /** First characters of the token, to tell tokens apart without revealing them. */
    #[ORM\Column(length: 12)]
    private string $tokenHint;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    private function __construct(string $name, OrgUnit $orgUnit, string $token)
    {
        $this->name = $name;
        $this->orgUnit = $orgUnit;
        $this->tokenHash = self::hash($token);
        $this->tokenHint = substr($token, 0, 12);
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * @return array{self, string} the entity and the plain token (not stored)
     */
    public static function issue(string $name, OrgUnit $orgUnit): array
    {
        $token = self::PREFIX.bin2hex(random_bytes(24));

        return [new self($name, $orgUnit, $token), $token];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getOrgUnit(): OrgUnit
    {
        return $this->orgUnit;
    }

    public function getTokenHint(): string
    {
        return $this->tokenHint;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isActive(): bool
    {
        return null === $this->revokedAt;
    }

    public function revoke(): void
    {
        $this->revokedAt ??= new \DateTimeImmutable();
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }
}
