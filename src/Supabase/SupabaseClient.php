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
     * @param array<string, mixed> $query
     */
    public function queryPrivate(string $method, string $path, array $query): mixed
    {
        $response = $this->httpClient->request($method, $this->baseUri.$path, [
            'query' => $query,
            'headers' => [
                'Accept' => 'application/json',
                'apikey' => $this->serviceRoleKey,
                'Authorization' => \sprintf('Bearer %s', $this->serviceRoleKey),
            ],
        ]);

        return $this->decodeResponse($response);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function rpc(string $path, array $body): mixed
    {
        $response = $this->httpClient->request('POST', $this->baseUri.$path, [
            'json' => $body,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'apikey' => $this->anonKey,
                'Authorization' => \sprintf('Bearer %s', $this->anonKey),
            ],
        ]);

        return $this->decodeResponse($response);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $extraHeaders
     */
    public function mutate(string $method, string $path, array $body, array $extraHeaders = []): mixed
    {
        $response = $this->httpClient->request($method, $this->baseUri.$path, [
            'json' => $body,
            'headers' => array_merge([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'apikey' => $this->serviceRoleKey,
                'Authorization' => \sprintf('Bearer %s', $this->serviceRoleKey),
                'Prefer' => 'return=representation',
            ], $extraHeaders),
        ]);

        return $this->decodeResponse($response);
    }

    public function uploadStorageObject(string $bucket, string $objectPath, string $contents, string $contentType): string
    {
        $objectUrl = $this->baseUri.'/storage/v1/object/'.$bucket.'/'.ltrim($objectPath, '/');

        // Delete any existing object at this path first, then POST without upsert.
        // Supabase Storage's upsert path triggers a server-side "delete old version"
        // step that crashes in local file-backend mode (TypeError on undefined path),
        // so we bypass it by always issuing a clean POST. 404 on delete is expected
        // when the path is new and is ignored.
        $this->httpClient->request('DELETE', $objectUrl, [
            'headers' => [
                'apikey' => $this->serviceRoleKey,
                'Authorization' => \sprintf('Bearer %s', $this->serviceRoleKey),
            ],
        ])->getStatusCode();

        $response = $this->httpClient->request('POST', $objectUrl, [
            'body' => $contents,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => $contentType,
                'apikey' => $this->serviceRoleKey,
                'Authorization' => \sprintf('Bearer %s', $this->serviceRoleKey),
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status >= 400) {
            $payload = $response->toArray(false);
            $message = $this->extractErrorMessage($payload) ?? \sprintf('HTTP %d', $status);
            throw new SupabaseApiException(\sprintf('Supabase storage upload failed (%s).', $message));
        }

        return $this->baseUri.'/storage/v1/object/public/'.$bucket.'/'.ltrim($objectPath, '/');
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
