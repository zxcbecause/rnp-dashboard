<?php

declare(strict_types=1);

namespace App\Entity\Raw;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Snapshot of GET /api/accommodations items (ACTIVE, INACTIVE and DELETED).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'raw_platform_accommodation')]
class RawPlatformAccommodation
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $salesActivationDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $salesDeactivationDate = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $syncedAt;

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function getSalesActivationDate(): ?\DateTimeImmutable
    {
        return $this->salesActivationDate;
    }

    public function getSalesDeactivationDate(): ?\DateTimeImmutable
    {
        return $this->salesDeactivationDate;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getSyncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
    }
}
