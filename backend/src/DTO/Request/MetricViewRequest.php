<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * PUT /api/org-units/{id}/metric-view: every active metric of the unit, in the desired order.
 */
final readonly class MetricViewRequest
{
    /**
     * @param list<MetricViewItem> $items
     */
    public function __construct(
        #[Assert\Count(min: 1, max: 500)]
        #[Assert\Valid]
        public array $items = [],
    ) {
    }
}
