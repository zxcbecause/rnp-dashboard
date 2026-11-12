<?php

declare(strict_types=1);

namespace App\Security\Ingest;

use App\Entity\Sync\IngestToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * "Authorization: Bearer rnp_…" on /api/ingest/*. The token is looked up by its SHA-256;
 * a missing, unknown or revoked token gets the same 401.
 */
final class IngestTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    private const string INVALID = 'Invalid or revoked ingest token.';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function supports(Request $request): ?bool
    {
        // Always authenticate on this firewall: no token is a 401, not an anonymous request.
        return true;
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (1 !== preg_match('/^Bearer\s+('.preg_quote(IngestToken::PREFIX, '/').'[0-9a-f]{48})$/', trim($header), $m)) {
            throw new CustomUserMessageAuthenticationException(self::INVALID);
        }

        $hash = IngestToken::hash($m[1]);

        return new SelfValidatingPassport(new UserBadge($hash, function (string $hash): IngestClient {
            $token = $this->em->getRepository(IngestToken::class)->findOneBy(['tokenHash' => $hash]);
            if (null === $token || !$token->isActive()) {
                throw new CustomUserMessageAuthenticationException(self::INVALID);
            }

            return new IngestClient($token);
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->unauthorized();
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized();
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(['error' => self::INVALID], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']);
    }
}
