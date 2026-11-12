<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Item of the notification bell. Texts are built on the client from type + fields.
 *  - call_exclusions_pending: requests waiting for the user's decision in a unit (count);
 *  - call_exclusion_decided: the user's own request was approved / rejected (status, callStartedAt, decidedBy);
 *  - alerts (Service/Notification/AlertCollector): metric_red, run_rate_behind, facts_missing (count, metrics, week),
 *    actions_missed, errors_new (count), data_stale (source, status "stale" | "failing"; no unit).
 */
final readonly class NotificationDto
{
    /**
     * @param list<string> $metrics   names of the first metrics concerned
     * @param list<int>    $metricIds their ids, in the same order
     */
    public function __construct(
        public string $type,
        public ?int $orgUnitId,
        public ?string $orgUnitName,
        /** ISO 8601: when the item appeared (the oldest pending request, the decision, the end of the week, ...) */
        public string $at,
        public ?int $count = null,
        public ?string $status = null,
        public ?string $callStartedAt = null,
        public ?string $decidedBy = null,
        public array $metrics = [],
        /** Y-m-d: start of the week segment the alert is about */
        public ?string $week = null,
        public ?string $source = null,
        public array $metricIds = [],
    ) {
    }
}
