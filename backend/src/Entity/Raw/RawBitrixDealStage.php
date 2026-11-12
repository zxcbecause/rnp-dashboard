<?php

declare(strict_types=1);

namespace App\Entity\Raw;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Deal stages of every Bitrix24 pipeline (crm.status.list, ENTITY_ID = DEAL_STAGE[_n]).
 * id = STATUS_ID ("C4:NEW"; the default pipeline has no prefix). "sort" orders the stages of a pipeline.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'raw_bitrix_deal_stage')]
class RawBitrixDealStage
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\Column]
    private int $categoryId = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column]
    private int $sort = 0;

    /** NULL = in progress, S = success, F = failure. */
    #[ORM\Column(length: 1, nullable: true)]
    private ?string $semantics = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $syncedAt;

    public function getId(): string
    {
        return $this->id;
    }
}
