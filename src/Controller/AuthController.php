<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth0\Auth0Client;
use App\Auth0\Auth0User;
use App\Auth0\Exception\Auth0AuthenticationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use App\Security\SessionLoginAuthenticator;

final class AuthController extends AbstractController
{
    private const SESSION_KEY = 'auth0_login';

    public function __construct(
        private readonly Auth0Client $auth0Client,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function login(Request $request): Response
    {
        $returnTo = $this->sanitizeReturnTo($request->query->get('returnTo'));

        if (null !== $this->getUser()) {
            return new RedirectResponse($returnTo);
        }

        $session = $request->getSession();
        $state = $this->randomBase64Url();
        $nonce = $this->randomBase64Url();
        $codeVerifier = $this->randomBase64Url(64);

        $session->set(self::SESSION_KEY, [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => $codeVerifier,
            'return_to' => $returnTo,
        ]);

        try {
            $url = $this->auth0Client->createAuthorizationUrl(
                $this->urlGenerator->generate('auth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL),
                $state,
                $nonce,
                $this->createCodeChallenge($codeVerifier),
            );
        } catch (Auth0AuthenticationException $exception) {
            $session->remove(self::SESSION_KEY);
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('marketplace_homepage');
        }

        return new RedirectResponse($url);
    }

    public function callback(Request $request): Response
    {
        $session = $request->getSession();
        $pendingLogin = $session->get(self::SESSION_KEY);

        if (!\is_array($pendingLogin)) {
            $this->addFlash('error', 'Authentication could not be completed. Please try again.');

            return $this->redirectToRoute('marketplace_homepage');
        }

        $requestState = $request->query->get('state');
        $requestCode = $request->query->get('code');

        if (!\is_string($requestState) || !isset($pendingLogin['state']) || $requestState !== $pendingLogin['state'] || !\is_string($requestCode) || '' === $requestCode) {
            $session->remove(self::SESSION_KEY);
            $this->addFlash('error', 'Authentication could not be completed. Please try again.');

            return $this->redirectToRoute('marketplace_homepage');
        }

        try {
            $tokens = $this->auth0Client->exchangeAuthorizationCode(
                $requestCode,
                $this->urlGenerator->generate('auth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL),
                (string) ($pendingLogin['code_verifier'] ?? ''),
            );
            $this->auth0Client->assertIdTokenNonce($tokens['id_token'] ?? null, (string) ($pendingLogin['nonce'] ?? ''));
            $userInfo = $this->auth0Client->fetchUserInfo($tokens['access_token']);
        } catch (Auth0AuthenticationException $exception) {
            $session->remove(self::SESSION_KEY);
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('marketplace_homepage');
        }

        $session->remove(self::SESSION_KEY);

        $user = new Auth0User(
            $userInfo['sub'],
            isset($userInfo['name']) && \is_string($userInfo['name']) ? $userInfo['name'] : null,
            isset($userInfo['email']) && \is_string($userInfo['email']) ? $userInfo['email'] : null,
            isset($userInfo['picture']) && \is_string($userInfo['picture']) ? $userInfo['picture'] : null,
        );

        $this->security->login($user, SessionLoginAuthenticator::class, 'main');

        return new RedirectResponse($this->sanitizeReturnTo($pendingLogin['return_to'] ?? null));
    }

    public function logout(Request $request): Response
    {
        $returnTo = $this->urlGenerator->generate('marketplace_homepage', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $this->tokenStorage->setToken(null);

        if ($request->hasSession()) {
            $this->invalidateSession($request->getSession());
        }

        try {
            return new RedirectResponse($this->auth0Client->createLogoutUrl($returnTo));
        } catch (Auth0AuthenticationException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('marketplace_homepage');
        }
    }

    private function sanitizeReturnTo(mixed $returnTo): string
    {
        if (!\is_string($returnTo) || '' === $returnTo || !str_starts_with($returnTo, '/')) {
            return '/';
        }

        if (str_starts_with($returnTo, '//')) {
            return '/';
        }

        return $returnTo;
    }

    private function randomBase64Url(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function createCodeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    private function invalidateSession(SessionInterface $session): void
    {
        $session->clear();
        $session->invalidate();
    }
}
