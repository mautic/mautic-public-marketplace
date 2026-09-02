<?php

declare(strict_types=1);

namespace App\Supabase;

use App\Supabase\Exception\SupabaseApiException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
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
        // Supabase Storage's upsert flow triggers a server-side "delete old version"
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

        try {
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new SupabaseApiException(\sprintf('Supabase storage upload failed: %s', $e->getMessage()), 0, $e);
        }

        if ($status >= 400) {
            $payload = $this->safeDecode($content);
            $message = $this->extractErrorMessage($payload) ?? ('' !== trim($content) ? $content : \sprintf('HTTP %d', $status));
            throw new SupabaseApiException(\sprintf('Supabase storage upload failed (%s).', $message));
        }

        return $this->baseUri.'/storage/v1/object/public/'.$bucket.'/'.ltrim($objectPath, '/');
    }

    private function decodeResponse(ResponseInterface $response): mixed
    {
        // getContent(false) never throws on a 4xx/5xx status (we inspect it ourselves) and,
        // unlike toArray(), never throws on a non-JSON body — so a proxy/storage HTML error
        // page or an empty response surfaces as a handled SupabaseApiException instead of an
        // uncaught JsonException that the API controllers can't translate into a JSON error.
        try {
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new SupabaseApiException(\sprintf('Supabase request failed: %s', $e->getMessage()), 0, $e);
        }

        if ($status >= 400) {
            $payload = $this->safeDecode($content);
            $message = $this->extractErrorMessage($payload) ?? ('' !== trim($content) ? $content : \sprintf('HTTP %d', $status));
            throw new SupabaseApiException(\sprintf('Supabase API error (%s).', $message));
        }

        // A successful but empty body (e.g. a 204 from a return=minimal mutation) decodes to
        // nothing; callers guard for arrays, so normalise it to an empty array.
        if ('' === trim($content)) {
            return [];
        }

        // A bare `null` is valid JSON: it is what PostgREST sends when an RPC returns SQL NULL,
        // e.g. get_pack for a package name that does not exist. safeDecode() cannot tell that
        // apart from an undecodable body, so it is handled here — callers read null as "no row"
        // and render a 404 instead of an API failure.
        if ('null' === trim($content)) {
            return null;
        }

        $payload = $this->safeDecode($content);
        if (null === $payload) {
            throw new SupabaseApiException('Supabase returned a non-JSON response.');
        }

        return $payload;
    }

    private function safeDecode(string $content): mixed
    {
        if ('' === trim($content)) {
            return null;
        }

        try {
            return json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
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
