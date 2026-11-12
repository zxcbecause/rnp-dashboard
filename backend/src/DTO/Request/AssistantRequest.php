<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/ai/assistant: the visible conversation, last message = the question.
 */
final readonly class AssistantRequest
{
    /**
     * @param list<array{role: string, content: string}> $messages
     */
    public function __construct(
        #[Assert\Count(min: 1, max: 200)]
        #[Assert\All([
            new Assert\Collection([
                'role' => [new Assert\NotBlank(), new Assert\Choice(choices: ['user', 'assistant'])],
                'content' => [new Assert\NotBlank(), new Assert\Type('string'), new Assert\Length(max: 40000)],
            ]),
        ])]
        public array $messages = [],
        #[Assert\Length(max: 200)]
        public ?string $page = null,
    ) {
    }
}
