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
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Auth0AuthenticationException
     */
    public function validateToken(string $token): array
    {
        try {
            $response = $this->httpClient->request('GET', \sprintf('https://%s/userinfo', $this->auth0Domain), [
                'headers' => [
                    'Authorization' => \sprintf('Bearer %s', $token),
                ],
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new Auth0AuthenticationException('Invalid or expired token.');
            }

            $data = $response->toArray(false);

            if (!isset($data['sub']) || !\is_string($data['sub'])) {
                throw new Auth0AuthenticationException('Invalid or expired token.');
            }

            return $data;
        } catch (TransportExceptionInterface|DecodingExceptionInterface $e) {
            $this->logger->error('Auth0 token validation failed.', ['exception' => $e]);

            throw new Auth0AuthenticationException('Invalid or expired token.', 0, $e);
        }
    }
}
