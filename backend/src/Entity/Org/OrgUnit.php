<?php

declare(strict_types=1);

namespace App\Entity\Org;

use App\Entity\Auth\User;
use App\Repository\Org\OrgUnitRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Node of the org tree. The tree itself is queried through the closure table {@see OrgUnitPath};
 * parent is kept for simple navigation and integrity. Paths are maintained by OrgTreeManager.
 */
#[ORM\Entity(repositoryClass: OrgUnitRepository::class)]
#[ORM\Table(name: 'org_unit')]
#[ORM\Index(name: 'idx_org_unit_parent_sort', columns: ['parent_id', 'sort'])]
class OrgUnit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?OrgUnit $parent;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'head_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $headUser = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $sort = 0;

    public function __construct(string $name, ?self $parent = null)
    {
        $this->name = $name;
        $this->parent = $parent;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getHeadUser(): ?User
    {
        return $this->headUser;
    }

    public function setHeadUser(?User $headUser): static
    {
        $this->headUser = $headUser;

        return $this;
    }

    public function getSort(): int
    {
        return $this->sort;
    }

    public function setSort(int $sort): static
    {
        $this->sort = $sort;

        return $this;
    }

    /**
     * Only through OrgTreeManager::moveUnit(), which rewrites the closure paths in the same transaction.
     *
     * @internal
     */
    public function setParent(self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }
}
