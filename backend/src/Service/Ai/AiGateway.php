<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Ai\AiUsage;
use App\Entity\Auth\User;
use App\Service\Ai\Llm\AiUnavailableException;
use App\Service\Ai\Llm\LlmClient;
use App\Service\Ai\Llm\LlmReply;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The only way features reach the model: checks that AI is configured, applies the per-user request limit
 * (cost control) and records the token usage of every call.
 */
final readonly class AiGateway
{
    public function __construct(
        private LlmClient $llm,
        private EntityManagerInterface $em,
        private RateLimiterFactoryInterface $aiRequestsLimiter,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->llm->isConfigured();
    }

    /**
     * Takes one request from the user's allowance; a chat answer that needs several model calls counts once.
     */
    public function admit(User $user): void
    {
        if (!$this->llm->isConfigured()) {
            throw AiUnavailableException::notConfigured();
        }

        $limit = $this->aiRequestsLimiter->create('user-'.$user->getId())->consume();
        if (!$limit->isAccepted()) {
            $minutes = max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - time()) / 60));

            throw new TooManyRequestsHttpException($minutes * 60, \sprintf('Слишком много запросов к ИИ. Попробуйте через %d мин.', $minutes));
        }
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed>|null  $jsonSchema
     */
    public function send(User $user, string $feature, string $system, array $messages, array $tools = [], string $effort = 'medium', int $maxTokens = 8000, ?array $jsonSchema = null): LlmReply
    {
        $reply = $this->llm->send($system, $messages, $tools, $effort, $maxTokens, $jsonSchema);

        $this->em->persist(new AiUsage($user, $feature, $reply->model, $reply->inputTokens, $reply->outputTokens, $reply->cacheReadTokens));
        $this->em->flush();

        return $reply;
    }
}
