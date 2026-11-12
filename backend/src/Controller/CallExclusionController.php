<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Calls\MissedCallsDto;
use App\DTO\NotificationDto;
use App\DTO\Request\CallExclusionDecisionRequest;
use App\DTO\Request\CallExclusionRequest;
use App\Entity\Auth\User;
use App\Service\Calls\CallExclusionHandler;
use App\Service\Notification\NotificationHandler;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[OA\Tag(name: 'Missed calls')]
final class CallExclusionController extends AbstractController
{
    #[Route('/api/org-units/{id}/missed-calls', name: 'api_missed_calls', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[OA\Parameter(name: 'from', in: 'query', description: 'Y-m-d; default: 13 days before "to"')]
    #[OA\Parameter(name: 'to', in: 'query', description: 'Y-m-d; default: today; at most 62 days')]
    #[OA\Parameter(name: 'all', in: 'query', description: 'true: every incoming call, not only the missed ones')]
    #[OA\Response(response: 200, description: 'Incoming calls (missed ones by default) with exclusion requests', content: new Model(type: MissedCallsDto::class))]
    public function list(int $id, CallExclusionHandler $handler, #[CurrentUser] User $user, #[MapQueryParameter] ?string $from = null, #[MapQueryParameter] ?string $to = null, #[MapQueryParameter(filter: \FILTER_VALIDATE_BOOL)] bool $all = false): JsonResponse
    {
        return $this->json($handler->list($id, $from, $to, $user, all: $all));
    }

    #[Route('/api/org-units/{id}/call-exclusions', name: 'api_call_exclusions_create', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[OA\RequestBody(content: new Model(type: CallExclusionRequest::class))]
    #[OA\Response(response: 201, description: '"status": approved when the head of the unit asked (applied at once), pending otherwise; "recalculated" as in the decision')]
    public function create(int $id, #[MapRequestPayload] CallExclusionRequest $request, #[CurrentUser] User $user, CallExclusionHandler $handler): JsonResponse
    {
        return $this->json($handler->request($id, $request, $user), Response::HTTP_CREATED);
    }

    #[Route('/api/call-exclusions/{id}/decision', name: 'api_call_exclusions_decide', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[OA\RequestBody(content: new Model(type: CallExclusionDecisionRequest::class))]
    #[OA\Response(response: 200, description: '"recalculated": false — the metrics are updated by the next sync (within 30 min)')]
    public function decide(int $id, #[MapRequestPayload] CallExclusionDecisionRequest $request, #[CurrentUser] User $user, CallExclusionHandler $handler): JsonResponse
    {
        return $this->json(['recalculated' => $handler->decide($id, $request, $user)]);
    }

    #[Route('/api/call-exclusions/{id}', name: 'api_call_exclusions_withdraw', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    public function withdraw(int $id, #[CurrentUser] User $user, CallExclusionHandler $handler): Response
    {
        $handler->withdraw($id, $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/call-exclusions/{id}/image', name: 'api_call_exclusions_image', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[OA\Response(response: 200, description: 'Screenshot of the request: {"contentType": "image/png", "data": base64}; 404 when there is none')]
    public function image(int $id, #[CurrentUser] User $user, CallExclusionHandler $handler): JsonResponse
    {
        return $this->json($handler->image($id, $user));
    }

    #[Route('/api/notifications', name: 'api_notifications', methods: ['GET'])]
    #[OA\Tag(name: 'Notifications')]
    #[OA\Response(response: 200, description: 'Notification bell items', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: NotificationDto::class))))]
    public function notifications(NotificationHandler $handler, #[CurrentUser] User $user): JsonResponse
    {
        return $this->json($handler->list($user));
    }
}
