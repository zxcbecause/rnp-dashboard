<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\IngestResultDto;
use App\Security\Ingest\IngestClient;
use App\Service\Ingest\MetricIngestHandler;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/ingest')]
#[OA\Tag(name: 'Push API')]
final class IngestController extends AbstractController
{
    #[Route('/metric-values', name: 'api_ingest_metric_values', methods: ['POST'])]
    #[OA\Post(
        description: 'Facts of push_api metrics from a source script. Header "Authorization: Bearer rnp_…" (app:ingest-token:create). '
            .'"date" is any day of the period (mapped to the metric week segment / month); "value" is a decimal string, null removes the fact. '
            .'All or nothing, up to 500 items.',
        security: [['IngestToken' => []]],
    )]
    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['values'],
        properties: [new OA\Property(property: 'values', type: 'array', maxItems: 500, items: new OA\Items(
            required: ['metric', 'date', 'value'],
            properties: [
                new OA\Property(property: 'metric', type: 'string', example: 'cs_nps'),
                new OA\Property(property: 'date', type: 'string', format: 'date', example: '2026-11-04'),
                new OA\Property(property: 'value', type: 'string', nullable: true, example: '8.5'),
            ],
        ))],
    ))]
    #[OA\Response(response: 200, description: 'Accepted', content: new Model(type: IngestResultDto::class))]
    #[OA\Response(response: 401, description: 'Missing, unknown or revoked token')]
    #[OA\Response(response: 422, description: 'Per-item violations ("values[2].value"); nothing was written')]
    public function metricValues(Request $request, #[CurrentUser] IngestClient $client, MetricIngestHandler $handler): JsonResponse
    {
        return $this->json($handler->ingest($client->token, $request->toArray()));
    }
}
