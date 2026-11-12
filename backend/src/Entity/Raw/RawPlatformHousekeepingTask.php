<?php

declare(strict_types=1);

namespace App\Entity\Raw;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Snapshot of GET /api/housekeeping-tasks items.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'raw_platform_housekeeping_task')]
#[ORM\Index(name: 'idx_raw_hk_task_due', columns: ['due_date'])]
class RawPlatformHousekeepingTask
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 16)]
    private string $status;

    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column(nullable: true)]
    private ?int $accommodationId = null;

    #[ORM\Column(nullable: true)]
    private ?int $bookingId = null;

    #[ORM\Column(nullable: true)]
    private ?int $assigneeId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $creationDate = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $syncedAt;

    public function getId(): int
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getAccommodationId(): ?int
    {
        return $this->accommodationId;
    }

    public function getBookingId(): ?int
    {
        return $this->bookingId;
    }

    public function getAssigneeId(): ?int
    {
        return $this->assigneeId;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function getCreationDate(): ?\DateTimeImmutable
    {
        return $this->creationDate;
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
