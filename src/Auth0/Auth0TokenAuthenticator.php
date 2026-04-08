<?php

declare(strict_types=1);

namespace App\Auth0;

use App\Auth0\Exception\Auth0AuthenticationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class Auth0TokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly Auth0Client $auth0Client,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has('Authorization')
            && str_starts_with($request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $token = substr($request->headers->get('Authorization', ''), 7);

        try {
            $userInfo = $this->auth0Client->validateToken($token);
        } catch (Auth0AuthenticationException $e) {
            throw new AuthenticationException($e->getMessage(), 0, $e);
        }

        $user = new Auth0User(
            $userInfo['sub'],
            $userInfo['name'] ?? null,
            $userInfo['email'] ?? null,
            $userInfo['picture'] ?? null,
        );

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), static fn (): Auth0User => $user)
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(
            ['error' => $exception->getMessage()],
            Response::HTTP_UNAUTHORIZED,
        );
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(
            ['error' => 'Missing or invalid Authorization header.'],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
