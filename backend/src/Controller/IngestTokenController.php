<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Request\IngestTokenCreateRequest;
use App\DTO\Sync\IngestTokenDto;
use App\Service\Ingest\IngestTokenManager;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Push API tokens for administrators (the same as app:ingest-token).
 */
#[Route('/api/admin/ingest-tokens')]
#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'Push API')]
final class IngestTokenController extends AbstractController
{
    #[Route('', name: 'api_admin_ingest_tokens', methods: ['GET'])]
    #[OA\Response(response: 200, description: 'All tokens, active first', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: IngestTokenDto::class))))]
    #[OA\Response(response: 403, description: 'Not an administrator')]
    public function list(IngestTokenManager $tokens): JsonResponse
    {
        return $this->json(array_map(IngestTokenDto::fromEntity(...), $tokens->list()));
    }

    #[Route('', name: 'api_admin_ingest_tokens_create', methods: ['POST'])]
    #[OA\RequestBody(content: new Model(type: IngestTokenCreateRequest::class))]
    #[OA\Response(response: 201, description: '{"token": "rnp_…" (shown once), "item": IngestTokenDto}')]
    #[OA\Response(response: 422, description: 'Bad or taken name, unknown org unit')]
    public function create(#[MapRequestPayload] IngestTokenCreateRequest $request, IngestTokenManager $tokens): JsonResponse
    {
        try {
            [$token, $plain] = $tokens->issue($request->name, $request->orgUnitId);
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $this->json(['token' => $plain, 'item' => IngestTokenDto::fromEntity($token)], Response::HTTP_CREATED);
    }

    #[Route('/{id}/revoke', name: 'api_admin_ingest_tokens_revoke', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[OA\Response(response: 200, description: 'Revoked token', content: new Model(type: IngestTokenDto::class))]
    public function revoke(int $id, IngestTokenManager $tokens): JsonResponse
    {
        $token = $tokens->find($id) ?? throw new NotFoundHttpException('Token not found.');
        $tokens->revoke($token);

        return $this->json(IngestTokenDto::fromEntity($token));
    }
}
