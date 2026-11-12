<?php

declare(strict_types=1);

namespace App\DTO\Ai;

final readonly class AssistantReplyDto
{
    /**
     * @param list<string> $used what the assistant looked at ("РНП-неделя", "динамика метрики"), shown under the answer
     */
    public function __construct(
        /** Markdown */
        public string $answer,
        public array $used,
    ) {
    }
}
