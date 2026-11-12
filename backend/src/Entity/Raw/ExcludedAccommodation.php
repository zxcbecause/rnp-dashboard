<?php

declare(strict_types=1);

namespace App\Entity\Raw;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A platform accommodation that no metric counts: test listings (Marta, 11.11.2026). Neither its bookings nor its
 * capacity reach any platform calculator. The id is the platform's one, without a foreign key: raw snapshots are
 * rewritten by the sync. Written by Integration\Platform\ExcludedAccommodations only.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'excluded_accommodation')]
class ExcludedAccommodation
{
    #[ORM\Id]
    #[ORM\Column]
    private int $accommodationId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $accommodationId, ?string $note)
    {
        $this->accommodationId = $accommodationId;
        $this->note = $note;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getAccommodationId(): int
    {
        return $this->accommodationId;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
