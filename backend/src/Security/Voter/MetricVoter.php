<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Metric\Metric;
use App\Security\Access\AccessScopeResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A metric is visible exactly when its org unit is visible.
 *
 * @extends Voter<string, Metric>
 */
final class MetricVoter extends Voter
{
    public const string VIEW = 'METRIC_VIEW';

    public function __construct(private readonly AccessScopeResolver $scopes)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW === $attribute && $subject instanceof Metric;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $this->scopes->resolve($token->getUser())->canSeeUnit($subject->getOrgUnit()->getId());
    }
}
