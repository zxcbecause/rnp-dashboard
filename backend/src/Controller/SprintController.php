<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Request\RecurringTaskRequest;
use App\DTO\Request\SprintTaskRequest;
use App\DTO\Sprint\SprintWeekDto;
use App\Entity\Auth\User;
use App\Service\Sprint\SprintHandler;
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

#[OA\Tag(name: 'Sprints')]
final class SprintController extends AbstractController
{
    #[Route('/api/org-units/{id}/sprint', name: 'api_sprint_week', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[OA\Parameter(name: 'week', in: 'query', description: 'Any day of the week (Y-m-d); default: today')]
    #[OA\Response(response: 200, description: 'Sprint of the week', content: new Model(type: SprintWeekDto::class))]
    public function week(int $id, SprintHandler $handler, #[CurrentUser] User $user, #[MapQueryParameter] ?string $week = null): JsonResponse
    {
        return $this->json($handler->week($id, $week, $user));
    }

    #[Route('/api/org-units/{id}/sprint-tasks', name: 'api_sprint_task_create', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[OA\RequestBody(content: new Model(type: SprintTaskRequest::class))]
    public function create(int $id, #[MapRequestPayload] SprintTaskRequest $request, #[CurrentUser] User $user, SprintHandler $handler): JsonResponse
    {
        return $this->json(['id' => $handler->create($id, $request, $user)], Response::HTTP_CREATED);
    }

    #[Route('/api/sprint-tasks/{id}', name: 'api_sprint_task_update', requirements: ['id' => Requirement::DIGITS], methods: ['PUT'])]
    #[OA\RequestBody(content: new Model(type: SprintTaskRequest::class))]
    public function update(int $id, #[MapRequestPayload] SprintTaskRequest $request, #[CurrentUser] User $user, SprintHandler $handler): Response
    {
        $handler->update($id, $request, $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/sprint-tasks/{id}', name: 'api_sprint_task_delete', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] User $user, SprintHandler $handler): Response
    {
        $handler->delete($id, $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/org-units/{id}/sprint/carry-over', name: 'api_sprint_carry', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[OA\Response(response: 200, description: 'Number of tasks copied into the next week')]
    public function carryOver(int $id, #[CurrentUser] User $user, SprintHandler $handler, #[MapQueryParameter] string $week): JsonResponse
    {
        return $this->json(['count' => $handler->carryOver($id, $week, $user)]);
    }

    #[Route('/api/org-units/{id}/sprint/pull-recurring', name: 'api_sprint_pull_recurring', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[OA\Response(response: 200, description: 'Number of recurring tasks added to the week')]
    public function pullRecurring(int $id, #[CurrentUser] User $user, SprintHandler $handler, #[MapQueryParameter] string $week): JsonResponse
    {
        return $this->json(['count' => $handler->pullRecurring($id, $week, $user)]);
    }

    #[Route('/api/org-units/{id}/recurring-tasks', name: 'api_recurring_create', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[OA\RequestBody(content: new Model(type: RecurringTaskRequest::class))]
    public function createRecurring(int $id, #[MapRequestPayload] RecurringTaskRequest $request, #[CurrentUser] User $user, SprintHandler $handler): JsonResponse
    {
        return $this->json(['id' => $handler->createRecurring($id, $request, $user)], Response::HTTP_CREATED);
    }

    #[Route('/api/recurring-tasks/{id}', name: 'api_recurring_update', requirements: ['id' => Requirement::DIGITS], methods: ['PUT'])]
    #[OA\RequestBody(content: new Model(type: RecurringTaskRequest::class))]
    public function updateRecurring(int $id, #[MapRequestPayload] RecurringTaskRequest $request, #[CurrentUser] User $user, SprintHandler $handler): Response
    {
        $handler->updateRecurring($id, $request, $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
