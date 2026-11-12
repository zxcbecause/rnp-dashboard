<?php

declare(strict_types=1);

namespace App\Entity\Raw;

use BcMath\Number;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Snapshot of GET /api/tickets items (service / improvement / incident).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'raw_platform_ticket')]
#[ORM\Index(name: 'idx_raw_ticket_creation', columns: ['creation_date'])]
class RawPlatformTicket
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 16)]
    private string $status;

    #[ORM\Column(length: 16)]
    private string $type;

    #[ORM\Column(length: 16)]
    private string $priority;

    #[ORM\Column(nullable: true)]
    private ?int $accommodationId = null;

    #[ORM\Column(type: Types::NUMBER, precision: 14, scale: 2, nullable: true)]
    private ?Number $price = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $creationDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $syncedAt;

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function getAccommodationId(): ?int
    {
        return $this->accommodationId;
    }

    public function getPrice(): ?Number
    {
        return $this->price;
    }

    public function getCreationDate(): ?\DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
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
