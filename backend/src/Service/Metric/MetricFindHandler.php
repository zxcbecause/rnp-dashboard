<?php

declare(strict_types=1);

namespace App\Service\Metric;

use App\DTO\MetricDto;
use App\Repository\Metric\MetricRepository;
use App\Security\Voter\MetricVoter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final readonly class MetricFindHandler
{
    public function __construct(
        private MetricRepository $metrics,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function handle(int $id): MetricDto
    {
        $metric = $this->metrics->find($id);

        if (null === $metric || !$this->authorization->isGranted(MetricVoter::VIEW, $metric)) {
            throw new NotFoundHttpException('Metric not found.');
        }

        return MetricDto::fromEntity($metric);
    }
}
