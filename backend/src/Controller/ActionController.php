<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Action\ActionsWeekDto;
use App\DTO\Request\BaseFunctionCheckRequest;
use App\DTO\Request\BaseFunctionRequest;
use App\DTO\Request\RoadmapEffectRequest;
use App\DTO\Request\RoadmapItemRequest;
use App\Entity\Auth\User;
use App\Service\Action\ActionsWeekHandler;
use App\Service\Action\ActionWriteHandler;
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

/**
 * Base functions (regular actions with checks), the roadmap with its history and Execution rate.
 */
#[OA\Tag(name: 'Actions')]
final class ActionController extends AbstractController
{
    #[Route('/api/org-units/{id}/actions', name: 'api_actions_week', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[OA\Response(response: 200, description: 'Actions screen of the unit for a week', content: new Model(type: ActionsWeekDto::class))]
    public function week(int $id, ActionsWeekHandler $handler, #[CurrentUser] User $user, #[MapQueryParameter] ?string $week = null): JsonResponse
    {
        return $this->json($handler->handle($id, $week, $user));
    }

    #[Route('/api/org-units/{id}/base-functions', name: 'api_base_function_create', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[OA\RequestBody(content: new Model(type: BaseFunctionRequest::class))]
    #[OA\Response(response: 201, description: 'Created')]
    public function createBaseFunction(int $id, #[MapRequestPayload] BaseFunctionRequest $request, #[CurrentUser] User $user, ActionWriteHandler $handler): JsonResponse
    {
        return $this->json(['id' => $handler->createBaseFunction($id, $request, $user)], Response::HTTP_CREATED);
    }

    #[Route('/api/base-functions/{id}', name: 'api_base_function_update', requirements: ['id' => Requirement::DIGITS], methods: ['PUT'])]
    #[OA\RequestBody(content: new Model(type: BaseFunctionRequest::class))]
    #[OA\Response(response: 204, description: 'Updated')]
    public function updateBaseFunction(int $id, #[MapRequestPayload] BaseFunctionRequest $request, #[CurrentUser] User $user, ActionWriteHandler $handler): Response
    {
        $handler->updateBaseFunction($id, $request, $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/base-functions/{id}/checks', name: 'api_base_function_check', requirements: ['id' => Requirement::DIGITS], methods: ['PUT'])]
    #[OA\RequestBody(content: new Model(type: BaseFunctionCheckRequest::class))]
    #[OA\Response(response: 204, description: 'Check saved (done = null removes it)')]
    public function check(int $id, #[MapRequestPayload] BaseFunctionCheckRequest $request, #[CurrentUser] User $user, ActionWriteHandler $handler): Response
    {
        $handler->check($id, $request, $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/metrics/{id}/roadmap', name: 'api_roadmap_create', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[OA\RequestBody(content: new Model(type: RoadmapItemRequest::class))]
    #[OA\Response(response: 201, description: 'Created')]
    public function createRoadmapItem(int $id, #[MapRequestPayload] RoadmapItemRequest $request, #[CurrentUser] User $user, ActionWriteHandler $handler): JsonResponse
    {
        return $this->json(['id' => $handler->createRoadmapItem($id, $request, $user)], Response::HTTP_CREATED);
    }

    #[Route('/api/roadmap-items/{id}', name: 'api_roadmap_update', requirements: ['id' => Requirement::DIGITS], methods: ['PUT'])]
    #[OA\RequestBody(content: new Model(type: RoadmapItemRequest::class))]
    #[OA\Response(response: 204, description: 'Updated; every change is recorded in the history')]
    public function updateRoadmapItem(int $id, #[MapRequestPayload] RoadmapItemRequest $request, #[CurrentUser] User $user, ActionWriteHandler $handler): Response
    {
        $handler->updateRoadmapItem($id, $request, $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/roadmap-items/{id}/effect', name: 'api_roadmap_effect', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[OA\RequestBody(content: new Model(type: RoadmapEffectRequest::class))]
    #[OA\Response(response: 204, description: 'Effect assessment recorded')]
    public function assessEffect(int $id, #[MapRequestPayload] RoadmapEffectRequest $request, #[CurrentUser] User $user, ActionWriteHandler $handler): Response
    {
        $handler->assessEffect($id, $request, $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
