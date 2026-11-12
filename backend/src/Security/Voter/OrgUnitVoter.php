<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Org\OrgUnit;
use App\Security\Access\AccessScopeResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, OrgUnit>
 */
final class OrgUnitVoter extends Voter
{
    public const string VIEW = 'ORG_UNIT_VIEW';

    public function __construct(private readonly AccessScopeResolver $scopes)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW === $attribute && $subject instanceof OrgUnit;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $this->scopes->resolve($token->getUser())->canSeeUnit($subject->getId());
    }
}
