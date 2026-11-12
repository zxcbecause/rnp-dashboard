<?php

declare(strict_types=1);

namespace App\Entity\Org;

use App\Entity\Auth\User;
use App\Enum\OrgRole;
use App\Repository\Org\OrgMembershipRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrgMembershipRepository::class)]
#[ORM\Table(name: 'org_membership')]
#[ORM\UniqueConstraint(name: 'uniq_org_membership_user_unit', columns: ['user_id', 'org_unit_id'])]
class OrgMembership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: OrgUnit::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OrgUnit $orgUnit;

    #[ORM\Column(length: 16, enumType: OrgRole::class)]
    private OrgRole $role;

    public function __construct(User $user, OrgUnit $orgUnit, OrgRole $role)
    {
        $this->user = $user;
        $this->orgUnit = $orgUnit;
        $this->role = $role;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getOrgUnit(): OrgUnit
    {
        return $this->orgUnit;
    }

    public function getRole(): OrgRole
    {
        return $this->role;
    }

    public function setRole(OrgRole $role): static
    {
        $this->role = $role;

        return $this;
    }
}
