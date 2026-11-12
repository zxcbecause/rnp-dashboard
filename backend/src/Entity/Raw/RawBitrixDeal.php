<?php

declare(strict_types=1);

namespace App\Entity\Raw;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Snapshot of Bitrix24 deals of the owner pipelines (crm.deal.list, id = Bitrix id).
 * Only non-personal fields are kept: no titles, contacts or comments.
 * Written by RawUpserter; datetimes are Europe/Madrid wall time.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'raw_bitrix_deal')]
#[ORM\Index(name: 'idx_raw_bx_deal_created', columns: ['date_create'])]
#[ORM\Index(name: 'idx_raw_bx_deal_category', columns: ['category_id'])]
class RawBitrixDeal
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(nullable: true)]
    private ?int $categoryId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $stageId = null;

    /** P = in progress, S = success, F = failure. */
    #[ORM\Column(length: 1, nullable: true)]
    private ?string $stageSemantic = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $sourceId = null;

    /** Label of the deal's "Source" user field (Marketing lead / Cold call). */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $ownerSource = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $utmSource = null;

    #[ORM\Column(nullable: true)]
    private ?int $assignedById = null;

    #[ORM\Column(nullable: true)]
    private ?int $leadId = null;

    #[ORM\Column]
    private bool $closed = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateCreate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateModify = null;

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
