<?php

declare(strict_types=1);

namespace App\Entity\Raw;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Stage transitions of Bitrix24 deals (crm.stagehistory.list, entityTypeId = 2). Append-only:
 * type 1 = deal created in the stage, 2 = moved to the stage, 3 = moved to another pipeline.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'raw_bitrix_deal_stage_history')]
#[ORM\Index(name: 'idx_raw_bx_stage_hist_deal', columns: ['deal_id'])]
#[ORM\Index(name: 'idx_raw_bx_stage_hist_time', columns: ['created_time'])]
class RawBitrixDealStageHistory
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column]
    private int $dealId = 0;

    #[ORM\Column(nullable: true)]
    private ?int $categoryId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $stageId = null;

    #[ORM\Column(length: 1, nullable: true)]
    private ?string $stageSemantic = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $typeId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $createdTime = null;

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
