<?php

declare(strict_types=1);

namespace App\Service\Ai\Llm;

final readonly class LlmToolCall
{
    /**
     * @param array<string, mixed> $input arguments chosen by the model: untrusted, validate before use
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $input,
    ) {
    }
}
