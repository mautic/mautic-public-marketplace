<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class Auth0LoginEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $returnTo = $this->determineReturnTo($request);

        return new RedirectResponse($this->urlGenerator->generate('auth_login', [
            'returnTo' => $returnTo,
        ]));
    }

    private function determineReturnTo(Request $request): string
    {
        if ($request->isMethodSafe()) {
            return $request->getRequestUri();
        }

        $referer = $request->headers->get('referer', '/');
        $path = parse_url($referer, \PHP_URL_PATH);

        if (!\is_string($path) || !str_starts_with($path, '/')) {
            return '/';
        }

        $query = parse_url($referer, \PHP_URL_QUERY);

        return null === $query ? $path : $path.'?'.$query;
    }
}
