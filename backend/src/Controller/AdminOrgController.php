<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Admin\AdminOrgUnitDto;
use App\DTO\Request\AdminHeadRequest;
use App\DTO\Request\AdminMemberRequest;
use App\DTO\Request\AdminOrgUnitRequest;
use App\Service\Admin\AdminOrgHandler;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The org tree for administrators (the same as app:org).
 */
#[Route('/api/admin/org-units')]
#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'Administration')]
final class AdminOrgController extends AbstractController
{
    #[Route('', name: 'api_admin_org_units', methods: ['GET'])]
    #[OA\Response(response: 200, description: 'Every unit with head and members (flat; parentId builds the tree)', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: AdminOrgUnitDto::class))))]
    #[OA\Response(response: 403, description: 'Not an administrator')]
    public function list(AdminOrgHandler $handler): JsonResponse
    {
        return $this->json($handler->list());
    }

    #[Route('', name: 'api_admin_org_units_create', methods: ['POST'])]
    #[OA\RequestBody(content: new Model(type: AdminOrgUnitRequest::class))]
    #[OA\Response(response: 201, description: 'Created unit', content: new Model(type: AdminOrgUnitDto::class))]
    #[OA\Response(response: 422, description: 'Empty or taken name, unknown parent')]
    public function create(#[MapRequestPayload] AdminOrgUnitRequest $request, AdminOrgHandler $handler): JsonResponse
    {
        return $this->json($handler->create($request), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_admin_org_units_update', requirements: ['id' => Requirement::DIGITS], methods: ['PUT'])]
    #[OA\RequestBody(content: new Model(type: AdminOrgUnitRequest::class))]
    #[OA\Response(response: 200, description: 'Renamed / moved unit (with its branch)', content: new Model(type: AdminOrgUnitDto::class))]
    #[OA\Response(response: 422, description: 'Taken name, move into its own branch or of the root')]
    public function update(int $id, #[MapRequestPayload] AdminOrgUnitRequest $request, AdminOrgHandler $handler): JsonResponse
    {
        return $this->json($handler->update($id, $request));
    }

    #[Route('/{id}/head', name: 'api_admin_org_units_head', requirements: ['id' => Requirement::DIGITS], methods: ['PUT'])]
    #[OA\RequestBody(content: new Model(type: AdminHeadRequest::class))]
    #[OA\Response(response: 200, description: 'Unit with the new head (null: no head)', content: new Model(type: AdminOrgUnitDto::class))]
    public function setHead(int $id, #[MapRequestPayload] AdminHeadRequest $request, AdminOrgHandler $handler): JsonResponse
    {
        return $this->json($handler->setHead($id, $request));
    }

    #[Route('/{id}/members', name: 'api_admin_org_units_member', requirements: ['id' => Requirement::DIGITS], methods: ['PUT'])]
    #[OA\RequestBody(content: new Model(type: AdminMemberRequest::class))]
    #[OA\Response(response: 200, description: 'Unit with the member added or its role changed', content: new Model(type: AdminOrgUnitDto::class))]
    public function setMember(int $id, #[MapRequestPayload] AdminMemberRequest $request, AdminOrgHandler $handler): JsonResponse
    {
        return $this->json($handler->setMember($id, $request));
    }

    #[Route('/{id}/members/{userId}', name: 'api_admin_org_units_member_remove', requirements: ['id' => Requirement::DIGITS, 'userId' => Requirement::DIGITS], methods: ['DELETE'])]
    #[OA\Response(response: 200, description: 'Unit without the member', content: new Model(type: AdminOrgUnitDto::class))]
    public function removeMember(int $id, int $userId, AdminOrgHandler $handler): JsonResponse
    {
        return $this->json($handler->removeMember($id, $userId));
    }
}
