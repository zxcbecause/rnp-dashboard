<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Ai\AiWeeklyReviewDto;
use App\DTO\Ai\AnomalyExplanationDto;
use App\DTO\Ai\AssistantReplyDto;
use App\DTO\Request\AnomalyRequest;
use App\DTO\Request\AssistantRequest;
use App\Entity\Auth\User;
use App\Service\Ai\AiGateway;
use App\Service\Ai\AnomalyExplainHandler;
use App\Service\Ai\Assistant\AssistantHandler;
use App\Service\Ai\WeeklyReviewHandler;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[OA\Tag(name: 'AI')]
final class AiController extends AbstractController
{
    #[Route('/api/org-units/{id}/ai/weekly-review', name: 'api_ai_weekly_review_get', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[OA\Parameter(name: 'week', in: 'query', description: 'Any day of the week (Y-m-d); default: current week')]
    #[OA\Response(response: 200, description: 'Stored AI review of the week (text null until generated)', content: new Model(type: AiWeeklyReviewDto::class))]
    public function weeklyReview(int $id, WeeklyReviewHandler $handler, #[CurrentUser] User $user, #[MapQueryParameter] ?string $week = null): JsonResponse
    {
        return $this->json($handler->get($id, $week, $user));
    }

    #[Route('/api/org-units/{id}/ai/weekly-review', name: 'api_ai_weekly_review_generate', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[OA\Parameter(name: 'week', in: 'query', description: 'Any day of the week (Y-m-d); default: current week')]
    #[OA\Response(response: 200, description: 'Freshly generated review (replaces the stored one)', content: new Model(type: AiWeeklyReviewDto::class))]
    #[OA\Response(response: 429, description: 'Per-user AI request limit reached')]
    #[OA\Response(response: 503, description: 'AI not configured or unavailable')]
    public function generateWeeklyReview(int $id, WeeklyReviewHandler $handler, #[CurrentUser] User $user, #[MapQueryParameter] ?string $week = null): JsonResponse
    {
        return $this->json($handler->generate($id, $week, $user));
    }

    #[Route('/api/ai/assistant', name: 'api_ai_assistant', methods: ['POST'])]
    #[OA\RequestBody(content: new Model(type: AssistantRequest::class))]
    #[OA\Response(response: 200, description: 'Answer of the AI assistant (Markdown) and what it looked at', content: new Model(type: AssistantReplyDto::class))]
    #[OA\Response(response: 429, description: 'Per-user AI request limit reached')]
    #[OA\Response(response: 503, description: 'AI not configured or unavailable')]
    public function assistant(#[MapRequestPayload] AssistantRequest $request, #[CurrentUser] User $user, AssistantHandler $handler): JsonResponse
    {
        return $this->json($handler->ask($request->messages, $request->page, $user));
    }

    #[Route('/api/ai/status', name: 'api_ai_status', methods: ['GET'])]
    #[OA\Response(response: 200, description: '{"available": bool} — whether AI features are configured')]
    public function status(AiGateway $gateway): JsonResponse
    {
        return $this->json(['available' => $gateway->isAvailable()]);
    }

    #[Route('/api/metrics/{id}/ai/anomaly', name: 'api_ai_anomaly', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[OA\RequestBody(content: new Model(type: AnomalyRequest::class))]
    #[OA\Response(response: 200, description: 'Why the metric is red in the week / behind for the month (Markdown); reused while its figures stay the same', content: new Model(type: AnomalyExplanationDto::class))]
    #[OA\Response(response: 404, description: 'Metric not visible to the user')]
    #[OA\Response(response: 429, description: 'Per-user AI request limit reached')]
    #[OA\Response(response: 503, description: 'AI not configured or unavailable')]
    public function anomaly(int $id, #[MapRequestPayload] AnomalyRequest $request, #[CurrentUser] User $user, AnomalyExplainHandler $handler): JsonResponse
    {
        return $this->json($handler->explain($id, $request->kind, $request->date, $user));
    }
}
