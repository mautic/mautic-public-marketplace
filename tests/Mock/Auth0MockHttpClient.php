<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class Auth0MockHttpClient extends MockHttpClient
{
    public function __construct()
    {
        parent::__construct(static function (string $method, string $url, array $options = []): MockResponse {
            if ('POST' === $method && str_contains($url, '/oauth/token')) {
                $payload = $options['json'] ?? [];

                if (!\is_array($payload) || 'bad-code' === ($payload['code'] ?? null)) {
                    return new MockResponse(
                        json_encode(['error' => 'invalid_grant'], \JSON_THROW_ON_ERROR),
                        ['http_code' => 401, 'response_headers' => ['content-type' => 'application/json']],
                    );
                }

                return new MockResponse(
                    json_encode([
                        'access_token' => 'test-access-token',
                        'id_token' => self::buildIdToken('test-nonce'),
                        'token_type' => 'Bearer',
                        'expires_in' => 3600,
                    ], \JSON_THROW_ON_ERROR),
                    ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
                );
            }

            if ('GET' === $method && str_contains($url, '/userinfo')) {
                $authorization = null;

                if (isset($options['headers']['Authorization']) && \is_string($options['headers']['Authorization'])) {
                    $authorization = $options['headers']['Authorization'];
                } elseif (isset($options['normalized_headers']['authorization'][0]) && \is_array($options['normalized_headers']['authorization'][0])) {
                    $authorization = $options['normalized_headers']['authorization'][0][1] ?? null;
                } elseif (isset($options['headers']) && \is_array($options['headers'])) {
                    foreach ($options['headers'] as $header) {
                        if (\is_string($header) && str_starts_with(strtolower($header), 'authorization:')) {
                            $authorization = trim(substr($header, \strlen('authorization:')));
                            break;
                        }
                    }
                }

                if ('Bearer test-access-token' !== $authorization) {
                    return new MockResponse(
                        json_encode(['error' => 'invalid_token'], \JSON_THROW_ON_ERROR),
                        ['http_code' => 401, 'response_headers' => ['content-type' => 'application/json']],
                    );
                }

                return new MockResponse(
                    json_encode([
                        'sub' => 'auth0|test123',
                        'name' => 'Test User',
                        'email' => 'test@example.com',
                        'picture' => null,
                    ], \JSON_THROW_ON_ERROR),
                    ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
                );
            }

            return new MockResponse(
                json_encode(['error' => 'not_found'], \JSON_THROW_ON_ERROR),
                ['http_code' => 404, 'response_headers' => ['content-type' => 'application/json']],
            );
        });
    }

    private static function buildIdToken(string $nonce): string
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'none', 'typ' => 'JWT'], \JSON_THROW_ON_ERROR));
        $payload = self::base64UrlEncode(json_encode([
            'sub' => 'auth0|test123',
            'nonce' => $nonce,
        ], \JSON_THROW_ON_ERROR));

        return $header.'.'.$payload.'.signature';
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
