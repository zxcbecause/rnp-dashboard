<?php

declare(strict_types=1);

namespace App\Entity\Raw;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Call activities of Bitrix24 CRM (crm.activity.list, TYPE_ID = 2). No subject, phone numbers or
 * descriptions are kept. direction: 1 = incoming, 2 = outgoing; missed = SETTINGS.MISSED_CALL.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'raw_bitrix_call')]
#[ORM\Index(name: 'idx_raw_bx_call_start', columns: ['start_time'])]
#[ORM\Index(name: 'idx_raw_bx_call_owner', columns: ['owner_type_id', 'owner_id', 'start_time'])]
class RawBitrixCall
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $direction = null;

    #[ORM\Column]
    private bool $missed = false;

    #[ORM\Column]
    private bool $completed = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startTime = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endTime = null;

    #[ORM\Column(nullable: true)]
    private ?int $ownerTypeId = null;

    #[ORM\Column(nullable: true)]
    private ?int $ownerId = null;

    #[ORM\Column(nullable: true)]
    private ?int $responsibleId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUpdated = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $syncedAt;

    public function getId(): int
    {
        return $this->id;
    }
}
