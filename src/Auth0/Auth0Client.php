<?php

declare(strict_types=1);

namespace App\Auth0;

use App\Auth0\Exception\Auth0AuthenticationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Auth0Client
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'AUTH0_DOMAIN')]
        private readonly string $auth0Domain,
        #[Autowire(env: 'AUTH0_CLIENT_ID')]
        private readonly string $auth0ClientId,
        #[Autowire(env: 'AUTH0_CLIENT_SECRET')]
        private readonly string $auth0ClientSecret,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Auth0AuthenticationException
     */
    public function exchangeAuthorizationCode(string $code, string $redirectUri, string $codeVerifier): array
    {
        $this->assertConfigured(true);

        try {
            $response = $this->httpClient->request('POST', \sprintf('https://%s/oauth/token', $this->auth0Domain), [
                'json' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->auth0ClientId,
                    'client_secret' => $this->auth0ClientSecret,
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'code_verifier' => $codeVerifier,
                ],
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new Auth0AuthenticationException('Failed to exchange the Auth0 authorization code.');
            }

            $data = $response->toArray(false);

            if (!isset($data['access_token']) || !\is_string($data['access_token'])) {
                throw new Auth0AuthenticationException('Auth0 did not return an access token.');
            }

            return $data;
        } catch (TransportExceptionInterface|DecodingExceptionInterface $e) {
            $this->logger->error('Auth0 token exchange failed.', ['exception' => $e]);

            throw new Auth0AuthenticationException('Failed to exchange the Auth0 authorization code.', 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Auth0AuthenticationException
     */
    public function fetchUserInfo(string $accessToken): array
    {
        $this->assertConfigured();

        try {
            $response = $this->httpClient->request('GET', \sprintf('https://%s/userinfo', $this->auth0Domain), [
                'headers' => [
                    'Authorization' => \sprintf('Bearer %s', $accessToken),
                ],
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new Auth0AuthenticationException('Failed to load the authenticated Auth0 user.');
            }

            $data = $response->toArray(false);

            if (!isset($data['sub']) || !\is_string($data['sub'])) {
                throw new Auth0AuthenticationException('Auth0 user info response is missing a user identifier.');
            }

            return $data;
        } catch (TransportExceptionInterface|DecodingExceptionInterface $e) {
            $this->logger->error('Auth0 user info request failed.', ['exception' => $e]);

            throw new Auth0AuthenticationException('Failed to load the authenticated Auth0 user.', 0, $e);
        }
    }

    public function createAuthorizationUrl(
        string $redirectUri,
        string $state,
        string $nonce,
        string $codeChallenge,
    ): string {
        $this->assertConfigured();

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->auth0ClientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', \PHP_QUERY_RFC3986);

        return \sprintf('https://%s/authorize?%s', $this->auth0Domain, $query);
    }

    public function createLogoutUrl(string $returnTo): string
    {
        $this->assertConfigured();

        $query = http_build_query([
            'client_id' => $this->auth0ClientId,
            'returnTo' => $returnTo,
        ], '', '&', \PHP_QUERY_RFC3986);

        return \sprintf('https://%s/v2/logout?%s', $this->auth0Domain, $query);
    }

    /**
     * @throws Auth0AuthenticationException
     */
    public function assertIdTokenNonce(?string $idToken, string $expectedNonce): void
    {
        if (null === $idToken || '' === $idToken) {
            throw new Auth0AuthenticationException('Auth0 did not return an ID token.');
        }

        $segments = explode('.', $idToken);
        if (\count($segments) < 2) {
            throw new Auth0AuthenticationException('Auth0 returned an invalid ID token.');
        }

        $payload = json_decode($this->base64UrlDecode($segments[1]), true);

        if (!\is_array($payload) || !isset($payload['nonce']) || !\is_string($payload['nonce'])) {
            throw new Auth0AuthenticationException('Auth0 returned an invalid ID token nonce.');
        }

        if ($payload['nonce'] !== $expectedNonce) {
            throw new Auth0AuthenticationException('Auth0 returned an unexpected ID token nonce.');
        }
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = (4 - \strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value.str_repeat('=', $padding), '-_', '+/'), true);

        if (false === $decoded) {
            throw new Auth0AuthenticationException('Auth0 returned an invalid ID token payload.');
        }

        return $decoded;
    }

    /**
     * @throws Auth0AuthenticationException
     */
    private function assertConfigured(bool $requiresSecret = false): void
    {
        if ('' === trim($this->auth0Domain) || '' === trim($this->auth0ClientId)) {
            throw new Auth0AuthenticationException('Auth0 is not configured. Set AUTH0_DOMAIN and AUTH0_CLIENT_ID.');
        }

        if ($requiresSecret && '' === trim($this->auth0ClientSecret)) {
            throw new Auth0AuthenticationException('Auth0 is not configured. Set AUTH0_CLIENT_SECRET.');
        }
    }
}
