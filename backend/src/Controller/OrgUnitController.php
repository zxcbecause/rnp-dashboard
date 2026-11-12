<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\MetricDto;
use App\DTO\OrgUnitDto;
use App\DTO\Request\MetricViewRequest;
use App\DTO\Rnp\RnpWeekDto;
use App\Entity\Auth\User;
use App\Service\Metric\MetricListByUnitHandler;
use App\Service\Metric\MetricViewHandler;
use App\Service\Org\OrgUnitFindHandler;
use App\Service\Org\OrgUnitListHandler;
use App\Service\Rnp\RnpWeekHandler;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/org-units')]
#[OA\Tag(name: 'Org units')]
final class OrgUnitController extends AbstractController
{
    #[Route('', name: 'api_org_units_list', methods: ['GET'])]
    #[OA\Response(response: 200, description: 'Visible org units', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: OrgUnitDto::class))))]
    public function list(OrgUnitListHandler $handler): JsonResponse
    {
        return $this->json($handler->handle());
    }

    #[Route('/{id}', name: 'api_org_units_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[OA\Response(response: 200, description: 'Org unit', content: new Model(type: OrgUnitDto::class))]
    #[OA\Response(response: 404, description: 'Missing or outside the viewer branch')]
    public function show(int $id, OrgUnitFindHandler $handler): JsonResponse
    {
        return $this->json(OrgUnitDto::fromEntity($handler->handle($id)));
    }

    #[Route('/{id}/metrics', name: 'api_org_units_metrics', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[OA\Response(response: 200, description: 'Active metrics of the unit', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: MetricDto::class))))]
    #[OA\Response(response: 404, description: 'Missing or outside the viewer branch')]
    public function metrics(int $id, MetricListByUnitHandler $handler): JsonResponse
    {
        return $this->json($handler->handle($id));
    }

    #[Route('/{id}/rnp', name: 'api_org_units_rnp', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[OA\Parameter(name: 'week', in: 'query', description: 'Any day of the week segment (Y-m-d); default: today', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'Weekly RNP screen of the unit', content: new Model(type: RnpWeekDto::class))]
    #[OA\Response(response: 404, description: 'Missing or outside the viewer branch')]
    public function rnp(int $id, RnpWeekHandler $handler, #[CurrentUser] User $user, #[MapQueryParameter] ?string $week = null, #[MapQueryParameter] bool $showHidden = false): JsonResponse
    {
        return $this->json($handler->handle($id, $week, $user, showHidden: $showHidden));
    }

    #[Route('/{id}/metric-view', name: 'api_org_units_metric_view_put', requirements: ['id' => Requirement::DIGITS], methods: ['PUT'])]
    #[OA\RequestBody(content: new Model(type: MetricViewRequest::class))]
    #[OA\Response(response: 204, description: 'Personal order and visibility saved')]
    #[OA\Response(response: 422, description: 'Not exactly the active metrics of the unit, or everything hidden')]
    public function saveMetricView(int $id, #[MapRequestPayload] MetricViewRequest $request, #[CurrentUser] User $user, MetricViewHandler $handler): JsonResponse
    {
        $handler->save($id, $request, $user);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/metric-view', name: 'api_org_units_metric_view_delete', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    #[OA\Response(response: 204, description: 'Personal view reset to the default order')]
    public function resetMetricView(int $id, #[CurrentUser] User $user, MetricViewHandler $handler): JsonResponse
    {
        $handler->reset($id, $user);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
