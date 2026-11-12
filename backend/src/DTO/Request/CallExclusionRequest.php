<?php

declare(strict_types=1);

namespace App\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/org-units/{id}/call-exclusions: leave an incoming call out of the statistics (after approval;
 * a request of the head of the unit is approved at once).
 */
final readonly class CallExclusionRequest
{
    public function __construct(
        #[Assert\Positive]
        public int $callId = 0,
        #[Assert\NotBlank(message: 'Укажите причину.')]
        #[Assert\Length(min: 10, max: 2000, minMessage: 'Опишите причину подробнее (не меньше {{ limit }} символов).')]
        public string $reason = '',
        #[Assert\Choice(choices: ['technical', 'spam', 'test', 'own', 'other'], message: 'Неизвестная категория причины.')]
        public string $category = 'technical',
        /** Optional screenshot: PNG, JPG or WebP up to 4 MB, base64 (checked by CallExclusionImage) */
        #[Assert\Length(max: 5600000, maxMessage: 'Картинка больше 4 МБ.')]
        public ?string $image = null,
    ) {
    }
}
