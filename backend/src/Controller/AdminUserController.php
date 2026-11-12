<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Admin\AdminUserDto;
use App\DTO\Request\AdminUserCreateRequest;
use App\DTO\Request\AdminUserUpdateRequest;
use App\Entity\Auth\User;
use App\Service\Admin\AdminUserHandler;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Accounts for administrators (the same as app:user).
 */
#[Route('/api/admin/users')]
#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'Administration')]
final class AdminUserController extends AbstractController
{
    #[Route('', name: 'api_admin_users', methods: ['GET'])]
    #[OA\Response(response: 200, description: 'All accounts, active first', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: AdminUserDto::class))))]
    #[OA\Response(response: 403, description: 'Not an administrator')]
    public function list(AdminUserHandler $handler): JsonResponse
    {
        return $this->json($handler->list());
    }

    #[Route('', name: 'api_admin_users_create', methods: ['POST'])]
    #[OA\RequestBody(content: new Model(type: AdminUserCreateRequest::class))]
    #[OA\Response(response: 201, description: '{"password": "…" (shown once), "item": AdminUserDto}')]
    #[OA\Response(response: 422, description: 'Bad or taken email, empty name')]
    public function create(#[MapRequestPayload] AdminUserCreateRequest $request, AdminUserHandler $handler): JsonResponse
    {
        return $this->json($handler->create($request), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_admin_users_update', requirements: ['id' => Requirement::DIGITS], methods: ['PUT'])]
    #[OA\RequestBody(content: new Model(type: AdminUserUpdateRequest::class))]
    #[OA\Response(response: 200, description: 'Updated account', content: new Model(type: AdminUserDto::class))]
    #[OA\Response(response: 422, description: 'Own rights or the last administrator, empty name')]
    public function update(int $id, #[MapRequestPayload] AdminUserUpdateRequest $request, #[CurrentUser] User $user, AdminUserHandler $handler): JsonResponse
    {
        return $this->json($handler->update($id, $request, $user));
    }

    #[Route('/{id}/reset-password', name: 'api_admin_users_reset_password', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[OA\Response(response: 200, description: '{"password": "…"} (shown once); every session of the account ends')]
    public function resetPassword(int $id, AdminUserHandler $handler): JsonResponse
    {
        return $this->json(['password' => $handler->resetPassword($id)]);
    }

    #[Route('/{id}', name: 'api_admin_users_delete', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    #[OA\Response(response: 204, description: 'Deleted; its metrics passed to the unit heads')]
    #[OA\Response(response: 422, description: 'Own account, or the account has history (deactivate it instead)')]
    public function delete(int $id, #[CurrentUser] User $user, AdminUserHandler $handler): Response
    {
        $handler->delete($id, $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
