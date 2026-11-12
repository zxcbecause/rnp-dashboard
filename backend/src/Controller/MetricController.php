<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\MetricDto;
use App\DTO\Request\MetricPeriodValueRequest;
use App\Entity\Auth\User;
use App\Service\Metric\MetricFindHandler;
use App\Service\Metric\MetricSeriesHandler;
use App\Service\Metric\MetricValueWriteHandler;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/metrics')]
#[OA\Tag(name: 'Metrics')]
final class MetricController extends AbstractController
{
    #[Route('/{id}', name: 'api_metrics_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[OA\Response(response: 200, description: 'Metric with its logic card', content: new Model(type: MetricDto::class))]
    #[OA\Response(response: 404, description: 'Missing or outside the viewer branch')]
    public function show(int $id, MetricFindHandler $handler): JsonResponse
    {
        return $this->json($handler->handle($id));
    }

    #[Route('/{id}/series', name: 'api_metrics_series', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[OA\Response(response: 200, description: 'Plan / fact / completion per week segment or month')]
    public function series(
        int $id,
        MetricSeriesHandler $handler,
        #[MapQueryParameter] string $period = 'week',
        #[MapQueryParameter] ?string $from = null,
        #[MapQueryParameter] ?string $to = null,
        #[MapQueryParameter] bool $compareYear = false,
    ): JsonResponse {
        return $this->json($handler->handle($id, $period, $from, $to, compareYear: $compareYear));
    }

    #[Route('/{id}/facts', name: 'api_metrics_fact_put', requirements: ['id' => Requirement::DIGITS], methods: ['PUT'])]
    #[OA\RequestBody(content: new Model(type: MetricPeriodValueRequest::class))]
    #[OA\Response(response: 200, description: 'Stored value (null when removed)')]
    #[OA\Response(response: 403, description: 'No right to enter facts')]
    #[OA\Response(response: 422, description: 'Invalid value, period or an automatically calculated metric')]
    public function putFact(int $id, #[MapRequestPayload] MetricPeriodValueRequest $request, #[CurrentUser] User $user, MetricValueWriteHandler $handler): JsonResponse
    {
        return $this->json(['value' => $handler->saveFact($id, $request, $user)]);
    }

    #[Route('/{id}/plans', name: 'api_metrics_plan_put', requirements: ['id' => Requirement::DIGITS], methods: ['PUT'])]
    #[OA\RequestBody(content: new Model(type: MetricPeriodValueRequest::class))]
    #[OA\Response(response: 200, description: 'Stored plan (null when removed)')]
    #[OA\Response(response: 403, description: 'No right to set plans')]
    public function putPlan(int $id, #[MapRequestPayload] MetricPeriodValueRequest $request, #[CurrentUser] User $user, MetricValueWriteHandler $handler): JsonResponse
    {
        return $this->json(['value' => $handler->savePlan($id, $request, $user)]);
    }
}
