<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ErrorLog\ErrorLogDto;
use App\DTO\Request\ErrorEntryRequest;
use App\Entity\Auth\User;
use App\Service\ErrorLog\ErrorLogHandler;
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

#[OA\Tag(name: 'Error log')]
final class ErrorLogController extends AbstractController
{
    #[Route('/api/org-units/{id}/errors', name: 'api_errors_list', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[OA\Parameter(name: 'from', in: 'query', description: 'Y-m-d; default: first day of the month two months ago')]
    #[OA\Parameter(name: 'to', in: 'query', description: 'Y-m-d; default: today')]
    #[OA\Response(response: 200, description: 'Error log of the unit', content: new Model(type: ErrorLogDto::class))]
    public function list(int $id, ErrorLogHandler $handler, #[CurrentUser] User $user, #[MapQueryParameter] ?string $from = null, #[MapQueryParameter] ?string $to = null): JsonResponse
    {
        return $this->json($handler->list($id, $from, $to, $user));
    }

    #[Route('/api/org-units/{id}/errors', name: 'api_errors_create', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[OA\RequestBody(content: new Model(type: ErrorEntryRequest::class))]
    public function create(int $id, #[MapRequestPayload] ErrorEntryRequest $request, #[CurrentUser] User $user, ErrorLogHandler $handler): JsonResponse
    {
        return $this->json(['id' => $handler->create($id, $request, $user)], Response::HTTP_CREATED);
    }

    #[Route('/api/errors/{id}', name: 'api_errors_update', requirements: ['id' => Requirement::DIGITS], methods: ['PUT'])]
    #[OA\RequestBody(content: new Model(type: ErrorEntryRequest::class))]
    public function update(int $id, #[MapRequestPayload] ErrorEntryRequest $request, #[CurrentUser] User $user, ErrorLogHandler $handler): Response
    {
        $handler->update($id, $request, $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
