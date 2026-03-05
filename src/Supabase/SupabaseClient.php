<?php

declare(strict_types=1);

namespace App\Supabase;

use App\Supabase\Exception\SupabaseApiException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class SupabaseClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'SUPABASE_API_BASE')]
        private readonly string $baseUri,
        #[Autowire(env: 'SUPABASE_ANON_KEY')]
        private readonly string $anonKey,
        #[Autowire(env: 'SUPABASE_SERVICE_ROLE_KEY')]
        private readonly string $serviceRoleKey,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public function query(string $method, string $path, array $query): mixed
    {
        $response = $this->httpClient->request($method, $this->baseUri.$path, [
            'query' => $query,
            'headers' => [
                'Accept' => 'application/json',
                'apikey' => $this->anonKey,
                'Authorization' => \sprintf('Bearer %s', $this->anonKey),
            ],
        ]);

        return $this->decodeResponse($response);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function mutate(string $method, string $path, array $body): void
    {
        $response = $this->httpClient->request($method, $this->baseUri.$path, [
            'json' => $body,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'apikey' => $this->serviceRoleKey,
                'Authorization' => \sprintf('Bearer %s', $this->serviceRoleKey),
                'Prefer' => 'return=representation',
            ],
        ]);

        $this->decodeResponse($response);
    }

    private function decodeResponse(ResponseInterface $response): mixed
    {
        $status = $response->getStatusCode();
        $payload = $response->toArray(false);

        if ($status >= 400) {
            $message = $this->extractErrorMessage($payload) ?? \sprintf('HTTP %d', $status);
            throw new SupabaseApiException(\sprintf('Supabase API error (%s).', $message));
        }

        return $payload;
    }

    private function extractErrorMessage(mixed $payload): ?string
    {
        if (!\is_array($payload)) {
            return null;
        }

        if (isset($payload['message']) && \is_string($payload['message'])) {
            return $payload['message'];
        }

        if (isset($payload['error']) && \is_string($payload['error'])) {
            return $payload['error'];
        }

        return null;
    }
}
