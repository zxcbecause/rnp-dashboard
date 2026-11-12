<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Ai\DeviationDraftDto;
use App\DTO\Request\DeviationDraftRequest;
use App\DTO\Request\DeviationNoteRequest;
use App\Entity\Auth\User;
use App\Service\Ai\DeviationDraftHandler;
use App\Service\Metric\DeviationNoteHandler;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[OA\Tag(name: 'Deviation notes')]
final class DeviationNoteController extends AbstractController
{
    #[Route('/api/metrics/{id}/deviation-note', name: 'api_deviation_note_save', requirements: ['id' => Requirement::DIGITS], methods: ['PUT'])]
    #[OA\RequestBody(content: new Model(type: DeviationNoteRequest::class))]
    #[OA\Response(response: 200, description: 'Stored note; {"note": null} when an empty reason removed it')]
    public function save(int $id, #[MapRequestPayload] DeviationNoteRequest $request, #[CurrentUser] User $user, DeviationNoteHandler $handler): JsonResponse
    {
        return $this->json(['note' => $handler->save($id, $request, $user)]);
    }

    #[Route('/api/metrics/{id}/ai/deviation-draft', name: 'api_ai_deviation_draft', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[OA\RequestBody(content: new Model(type: DeviationDraftRequest::class))]
    #[OA\Response(response: 200, description: 'AI proposal for the note (not saved)', content: new Model(type: DeviationDraftDto::class))]
    #[OA\Response(response: 429, description: 'Per-user AI request limit reached')]
    #[OA\Response(response: 503, description: 'AI not configured or unavailable')]
    public function draft(int $id, #[MapRequestPayload] DeviationDraftRequest $request, #[CurrentUser] User $user, DeviationDraftHandler $handler): JsonResponse
    {
        return $this->json($handler->draft($id, $request->periodType, $request->periodStart, $user));
    }
}
