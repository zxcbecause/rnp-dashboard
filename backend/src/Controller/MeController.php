<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\MeDto;
use App\DTO\Request\ChangePasswordRequest;
use App\Entity\Auth\User;
use App\Service\Auth\MeHandler;
use App\Service\Auth\UserAccountManager;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class MeController extends AbstractController
{
    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    #[OA\Tag(name: 'Auth')]
    #[OA\Response(response: 200, description: 'Current user, memberships and cabinet entry points', content: new Model(type: MeDto::class))]
    public function __invoke(#[CurrentUser] User $user, MeHandler $handler): JsonResponse
    {
        return $this->json($handler->handle($user));
    }

    #[Route('/api/me/password', name: 'api_me_password', methods: ['PUT'])]
    #[OA\Tag(name: 'Auth')]
    #[OA\RequestBody(content: new Model(type: ChangePasswordRequest::class))]
    #[OA\Response(response: 204, description: 'Password changed; every session (refresh token) is revoked, sign in again')]
    #[OA\Response(response: 422, description: 'Wrong current password or a weak new one')]
    #[OA\Response(response: 429, description: 'More than 5 attempts in 15 minutes')]
    public function changePassword(
        #[MapRequestPayload] ChangePasswordRequest $request,
        #[CurrentUser] User $user,
        UserAccountManager $accounts,
        RateLimiterFactoryInterface $passwordChangeLimiter,
    ): Response {
        if (!$passwordChangeLimiter->create('user-'.$user->getId())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(900, 'Слишком много попыток. Попробуйте позже.');
        }

        try {
            $accounts->changePassword($user, $request->currentPassword, $request->newPassword);
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
