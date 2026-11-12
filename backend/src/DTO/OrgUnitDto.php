<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Org\OrgUnit;

final readonly class OrgUnitDto
{
    public function __construct(
        public int $id,
        public ?int $parentId,
        public string $name,
        public ?UserRefDto $headUser,
        public int $sort,
    ) {
    }

    public static function fromEntity(OrgUnit $unit): self
    {
        return new self(
            (int) $unit->getId(),
            $unit->getParent()?->getId(),
            $unit->getName(),
            UserRefDto::fromNullable($unit->getHeadUser()),
            $unit->getSort(),
        );
    }
}
