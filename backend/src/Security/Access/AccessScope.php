<?php

declare(strict_types=1);

namespace App\Security\Access;

/**
 * Immutable answer to "which org units may this viewer see and change".
 *
 * Never open by accident: a viewer without memberships gets an empty scope, not "everything".
 */
final readonly class AccessScope
{
    /**
     * @param list<int> $unitIds     visible org unit ids (ignored when $all is true)
     * @param list<int> $rootIds     top-most visible units, i.e. where the viewer's cabinet starts
     * @param list<int> $factUnitIds units where the viewer may enter manual facts
     * @param list<int> $planUnitIds units where the viewer may set plans
     */
    private function __construct(
        public ?int $userId,
        public bool $all,
        public array $unitIds,
        public array $rootIds,
        public array $factUnitIds = [],
        public array $planUnitIds = [],
    ) {
    }

    /**
     * @param list<int> $rootIds
     */
    public static function everything(?int $userId, array $rootIds): self
    {
        return new self($userId, true, [], $rootIds);
    }

    /**
     * @param list<int> $unitIds
     * @param list<int> $rootIds
     * @param list<int> $factUnitIds
     * @param list<int> $planUnitIds
     */
    public static function units(?int $userId, array $unitIds, array $rootIds, array $factUnitIds = [], array $planUnitIds = []): self
    {
        return new self(
            $userId,
            false,
            array_values(array_unique($unitIds)),
            $rootIds,
            array_values(array_unique($factUnitIds)),
            array_values(array_unique($planUnitIds)),
        );
    }

    public static function denied(?int $userId = null): self
    {
        return new self($userId, false, [], []);
    }

    public function canSeeUnit(?int $unitId): bool
    {
        if (null === $unitId) {
            return false;
        }

        return $this->all || \in_array($unitId, $this->unitIds, true);
    }

    public function canEnterFacts(?int $unitId): bool
    {
        return null !== $unitId && ($this->all || \in_array($unitId, $this->factUnitIds, true));
    }

    public function canSetPlans(?int $unitId): bool
    {
        return null !== $unitId && ($this->all || \in_array($unitId, $this->planUnitIds, true));
    }

    public function isEmpty(): bool
    {
        return !$this->all && [] === $this->unitIds;
    }
}
