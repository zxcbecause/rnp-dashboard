<?php

declare(strict_types=1);

namespace App\Entity\Raw;

use BcMath\Number;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bookings created on a day as they were that evening (23:55 Madrid), like Gleb's nightly Avantio export: the
 * platform keeps no cancellation date nor history, so a booking cancelled or repriced later can only be seen
 * "as of the day" from this snapshot. Written by BookingDaySnapshotter only; datetimes are Madrid wall time.
 * Same non-personal columns as raw_platform_booking.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'booking_day_snapshot')]
#[ORM\UniqueConstraint(name: 'uniq_booking_day_snapshot', columns: ['snapshot_date', 'booking_id'])]
class BookingDaySnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The creation day the snapshot covers. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $snapshotDate;

    #[ORM\Column]
    private int $bookingId;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $portal = null;

    #[ORM\Column(type: Types::NUMBER, precision: 14, scale: 2, nullable: true)]
    private ?Number $total = null;

    #[ORM\Column(type: Types::NUMBER, precision: 14, scale: 2, nullable: true)]
    private ?Number $rentalPrice = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $creationDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $arrivalDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $departureDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastModification = null;

    #[ORM\Column(nullable: true)]
    private ?int $accommodationId = null;

    /** When the snapshot was taken (Madrid). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $takenAt;

    public function getId(): ?int
    {
        return $this->id;
    }
}
