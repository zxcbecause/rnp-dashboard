<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\DTO\NotificationDto;
use App\Entity\Auth\User;
use App\Service\Calls\CallExclusionHandler;

/**
 * Notification bell: computed from the current state, nothing is stored (decision 09.11.2026: notifications only
 * in the bell for now). Alerts first (AlertCollector), then pending call exclusions for their moderators and
 * decisions on the user's own requests of the last 7 days.
 */
final readonly class NotificationHandler
{
    private const string DECIDED_WINDOW = '-7 days';

    public function __construct(private CallExclusionHandler $calls, private AlertCollector $alerts)
    {
    }

    /**
     * @return list<NotificationDto>
     */
    public function list(User $user, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $items = $this->alerts->collect($user, $now);

        foreach ($this->calls->pendingForModerator($user) as ['unit' => $unit, 'count' => $count, 'oldest' => $oldest]) {
            $items[] = new NotificationDto('call_exclusions_pending', (int) $unit->getId(), $unit->getName(), $oldest->format(\DATE_ATOM), count: $count);
        }

        foreach ($this->calls->decidedForRequester($user, $now->modify(self::DECIDED_WINDOW)) as $exclusion) {
            $items[] = new NotificationDto(
                'call_exclusion_decided',
                (int) $exclusion->getOrgUnit()->getId(),
                $exclusion->getOrgUnit()->getName(),
                ($exclusion->getDecidedAt() ?? $exclusion->getRequestedAt())->format(\DATE_ATOM),
                status: $exclusion->getStatus()->value,
                callStartedAt: $exclusion->getCallStartedAt()->format('Y-m-d\TH:i:s'),
                decidedBy: $exclusion->getDecidedBy()?->getName(),
            );
        }

        return $items;
    }
}
