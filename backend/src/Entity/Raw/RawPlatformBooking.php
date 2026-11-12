<?php

declare(strict_types=1);

namespace App\Entity\Raw;

use BcMath\Number;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Snapshot of GET /api/bookings items of the Acme platform (id = platform id).
 * Written by RawUpserter (DBAL upsert); the entity exists for the schema and read access.
 * Datetimes are stored as Europe/Madrid wall time.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'raw_platform_booking')]
#[ORM\Index(name: 'idx_raw_booking_creation', columns: ['creation_date'])]
#[ORM\Index(name: 'idx_raw_booking_arrival', columns: ['arrival_date'])]
#[ORM\Index(name: 'idx_raw_booking_departure', columns: ['departure_date'])]
#[ORM\Index(name: 'idx_raw_booking_modified', columns: ['last_modification'])]
class RawPlatformBooking
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $portal = null;

    #[ORM\Column(type: Types::NUMBER, precision: 14, scale: 2, nullable: true)]
    private ?Number $total = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $arrivalDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $departureDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $creationDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastModification = null;

    #[ORM\Column(nullable: true)]
    private ?int $accommodationId = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $syncedAt;

    /** Rental part of the price (VAT included; no cleaning, extras or tourist tax). Detail card only. */
    #[ORM\Column(type: Types::NUMBER, precision: 14, scale: 2, nullable: true)]
    private ?Number $rentalPrice = null;

    /** Detail card only. */
    #[ORM\Column(type: Types::NUMBER, precision: 14, scale: 2, nullable: true)]
    private ?Number $portalCommission = null;

    /** lastModification of the booking when its detail card was fetched; null = never fetched. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $detailModification = null;

    /**
     * When the booking was cancelled: its lastModification at the first sync that saw it CANCELLED, so later edits
     * do not move it (the platform keeps no cancellation date). Bookings cancelled before 10.11.2026 got their
     * lastModification at that time. Null when not cancelled.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getPortal(): ?string
    {
        return $this->portal;
    }

    public function getTotal(): ?Number
    {
        return $this->total;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getArrivalDate(): ?\DateTimeImmutable
    {
        return $this->arrivalDate;
    }

    public function getDepartureDate(): ?\DateTimeImmutable
    {
        return $this->departureDate;
    }

    public function getCreationDate(): ?\DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getLastModification(): ?\DateTimeImmutable
    {
        return $this->lastModification;
    }

    public function getAccommodationId(): ?int
    {
        return $this->accommodationId;
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

    public function getRentalPrice(): ?Number
    {
        return $this->rentalPrice;
    }

    public function getPortalCommission(): ?Number
    {
        return $this->portalCommission;
    }

    public function getDetailModification(): ?\DateTimeImmutable
    {
        return $this->detailModification;
    }
}
