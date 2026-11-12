<?php

declare(strict_types=1);

namespace App\Service\Org;

use App\Entity\Org\OrgUnit;
use App\Repository\Org\OrgUnitRepository;
use App\Security\Voter\OrgUnitVoter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Loads an org unit the current user may see. Missing and foreign units answer the same 404,
 * so nobody can probe the tree outside their branch.
 */
final readonly class OrgUnitFindHandler
{
    public function __construct(
        private OrgUnitRepository $units,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function handle(int $id): OrgUnit
    {
        $unit = $this->units->find($id);

        if (null === $unit || !$this->authorization->isGranted(OrgUnitVoter::VIEW, $unit)) {
            throw new NotFoundHttpException('Org unit not found.');
        }

        return $unit;
    }
}
