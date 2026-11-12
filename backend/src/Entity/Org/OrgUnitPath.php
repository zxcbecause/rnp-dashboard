<?php

declare(strict_types=1);

namespace App\Entity\Org;

use App\Repository\Org\OrgUnitPathRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Closure table row: ancestor reaches descendant at the given depth.
 * Every node has a self row (depth 0), so "the branch of X" is simply WHERE ancestor = X.
 */
#[ORM\Entity(repositoryClass: OrgUnitPathRepository::class, readOnly: true)]
#[ORM\Table(name: 'org_unit_path')]
#[ORM\Index(name: 'idx_org_unit_path_descendant', columns: ['descendant_id', 'depth'])]
class OrgUnitPath
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: OrgUnit::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private OrgUnit $ancestor;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: OrgUnit::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private OrgUnit $descendant;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $depth;

    public function __construct(OrgUnit $ancestor, OrgUnit $descendant, int $depth)
    {
        $this->ancestor = $ancestor;
        $this->descendant = $descendant;
        $this->depth = $depth;
    }

    public function getAncestor(): OrgUnit
    {
        return $this->ancestor;
    }

    public function getDescendant(): OrgUnit
    {
        return $this->descendant;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }
}
