<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\DTO\MeDto;
use App\Entity\Auth\User;
use App\Repository\Org\OrgMembershipRepository;
use App\Security\Access\AccessScopeResolver;
use App\Service\Calls\CallExclusionHandler;

final readonly class MeHandler
{
    public function __construct(
        private OrgMembershipRepository $memberships,
        private AccessScopeResolver $scopes,
        private CallExclusionHandler $calls,
    ) {
    }

    public function handle(User $user): MeDto
    {
        return MeDto::fromEntity($user, $this->memberships->findForUser($user), $this->scopes->resolve($user), $this->calls->visibleCallUnits($user));
    }
}
