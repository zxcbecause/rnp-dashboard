<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Sync\DataStatusDto;
use App\Service\Sync\DataStatusHandler;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class DataStatusController extends AbstractController
{
    #[Route('/api/data-status', name: 'api_data_status', methods: ['GET'])]
    #[OA\Tag(name: 'Sync')]
    #[OA\Response(response: 200, description: 'Freshness of the platform and Bitrix data; error texts for admins only', content: new Model(type: DataStatusDto::class))]
    public function __invoke(DataStatusHandler $handler): JsonResponse
    {
        return $this->json($handler->handle($this->isGranted('ROLE_ADMIN')));
    }
}
