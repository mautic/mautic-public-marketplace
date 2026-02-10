<?php

declare(strict_types=1);

namespace App\Marketplace;

use App\Marketplace\Dto\PackageDetail;
use App\Marketplace\Dto\PackageListResult;
use App\Marketplace\Dto\PackageSummary;
use App\Marketplace\Dto\ReviewRequest;
use App\Marketplace\Exception\MarketplaceApiException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class MarketplaceApiClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $anonKey,
        private readonly string $serviceRoleKey,
    ) {
    }

    public function listPackages(
        int $limit = 10,
        int $offset = 0,
        string $orderBy = 'name',
        string $orderDir = 'asc',
        ?string $type = null,
        ?string $query = null,
        ?string $mauticVersion = null,
    ): PackageListResult {
        $params = [
            '_limit' => $limit,
            '_offset' => $offset,
            '_orderby' => $orderBy,
            '_orderdir' => $orderDir,
        ];

        if (null !== $query && '' !== $query) {
            $params['_query'] = $query;
        }

        if (null !== $type && '' !== $type) {
            $params['_type'] = $type;
        }

        if (null !== $mauticVersion && '' !== $mauticVersion) {
            $params['_smv'] = $mauticVersion;
        }

        $data = $this->requestJson('GET', '/rest/v1/rpc/get_view', $params);

        $payload = $this->normalizeListPayload($data);
        $rows = $payload['rows'];
        $total = $payload['total'];

        $items = [];
        foreach ($rows as $row) {
            if (!isset($row['name'])) {
                continue;
            }
            $items[] = new PackageSummary(
                (string) $row['name'],
                $row['displayname'] ?? null,
                $row['description'] ?? null,
                $row['type'] ?? null,
                $row['repository'] ?? null,
                $this->toInt($row['github_stars'] ?? null),
                $this->toInt($row['github_forks'] ?? null),
                $this->toInt($row['github_open_issues'] ?? null),
                $row['language'] ?? null,
                $this->toInt($row['favers'] ?? null),
                $this->toInt($row['downloads'] ?? null),
                $this->toFloat($row['average_rating'] ?? null),
                $this->toInt($row['total_review'] ?? null),
                $this->toBool($row['latest_mautic_support'] ?? null),
                $this->toDateTime($row['time'] ?? null),
            );
        }

        return new PackageListResult($items, $limit, $offset, $total);
    }

    public function getPackage(string $packageName): ?PackageDetail
    {
        $params = [
            'packag_name' => $packageName,
        ];

        $data = $this->requestJson('GET', '/rest/v1/rpc/get_pack', $params);

        if (null === $data || [] === $data) {
            return null;
        }

        $row = $data['package'] ?? null;
        if (!\is_array($row) || !isset($row['name'])) {
            throw new MarketplaceApiException('Unexpected response from Supabase get_pack.');
        }

        return new PackageDetail(
            (string) $row['name'],
            $row['displayname'] ?? null,
            $row['description'] ?? null,
            $row['type'] ?? null,
            $row['repository'] ?? null,
            $this->toInt($row['github_stars'] ?? null),
            $this->toInt($row['github_watchers'] ?? null),
            $this->toInt($row['github_forks'] ?? null),
            $this->toInt($row['github_open_issues'] ?? null),
            $row['language'] ?? null,
            $this->toInt($row['dependents'] ?? null),
            $this->toInt($row['suggesters'] ?? null),
            $this->toArray($row['downloads'] ?? null),
            $this->toInt($row['favers'] ?? null),
            $row['url'] ?? null,
            $this->toBool($row['isreviewed'] ?? null),
            $this->toBool($row['latest_mautic_support'] ?? null),
            $this->toArray($row['maintainers'] ?? null),
            isset($row['time']) ? (string) $row['time'] : null,
            $this->toArray($row['reviews'] ?? null),
            $this->toArray($row['versions'] ?? null),
        );
    }

    public function submitReview(string $packageName, string $userId, string $userName, ?string $picture, ReviewRequest $reviewRequest): void
    {
        $this->requestJsonWithServiceRole('POST', '/rest/v1/reviews', [
            'objectId' => $packageName,
            'user_id' => $this->auth0SubToUuid($userId),
            'user' => $userName,
            'picture' => $picture,
            'rating' => $reviewRequest->rating,
            'review' => $reviewRequest->review,
        ]);
    }

    private function auth0SubToUuid(string $sub): string
    {
        $hash = sha1($sub);

        return \sprintf(
            '%s-%s-5%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requestJsonWithServiceRole(string $method, string $path, array $body): void
    {
        $response = $this->httpClient->request($method, $path, [
            'json' => $body,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'apikey' => $this->serviceRoleKey,
                'Authorization' => \sprintf('Bearer %s', $this->serviceRoleKey),
                'Prefer' => 'return=minimal',
            ],
        ]);

        $status = $response->getStatusCode();

        if ($status >= 400) {
            $payload = $response->toArray(false);
            $message = $this->extractErrorMessage($payload) ?? \sprintf('HTTP %d', $status);
            throw new MarketplaceApiException(\sprintf('Supabase API error (%s).', $message));
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private function requestJson(string $method, string $path, array $query): mixed
    {
        $response = $this->httpClient->request($method, $path, [
            'query' => $query,
            'headers' => [
                'Accept' => 'application/json',
                'apikey' => $this->anonKey,
                'Authorization' => \sprintf('Bearer %s', $this->anonKey),
            ],
        ]);

        return $this->decodeResponse($response);
    }

    private function decodeResponse(ResponseInterface $response): mixed
    {
        try {
            $status = $response->getStatusCode();
            $payload = $response->toArray(false);

            if ($status >= 400) {
                $message = $this->extractErrorMessage($payload) ?? \sprintf('HTTP %d', $status);
                throw new MarketplaceApiException(\sprintf('Supabase API error (%s).', $message));
            }

            return $payload;
        } catch (\Throwable $exception) {
            if ($exception instanceof MarketplaceApiException) {
                throw $exception;
            }

            $status = $response->getStatusCode();
            throw new MarketplaceApiException(\sprintf('Supabase API error (HTTP %d).', $status), 0, $exception);
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

    private function toInt(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function toBool(mixed $value): ?bool
    {
        if (null === $value) {
            return null;
        }

        if (\is_bool($value)) {
            return $value;
        }

        if (1 === $value || '1' === $value || 'true' === $value) {
            return true;
        }

        if (0 === $value || '0' === $value || 'false' === $value) {
            return false;
        }

        return null;
    }

    private function toFloat(mixed $value): ?float
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<mixed>|null
     */
    private function toArray(mixed $value): ?array
    {
        return \is_array($value) ? $value : null;
    }

    private function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{rows: array, total: ?int}
     */
    /**
     * @param array<int|string, mixed> $data
     *
     * @return array{rows: array<int, array<string, mixed>>, total: ?int}
     */
    private function normalizeListPayload(array $data): array
    {
        if ([] === $data) {
            return ['rows' => [], 'total' => 0];
        }

        if ($this->isAssoc($data) && \array_key_exists('results', $data)) {
            $rows = $this->normalizeRows($this->toArray($data['results']) ?? []);
            $total = $this->toInt($data['total'] ?? null);

            return ['rows' => $rows, 'total' => $total];
        }

        $first = $data[0] ?? null;
        if (\is_array($first) && \array_key_exists('results', $first)) {
            $rows = $this->normalizeRows($this->toArray($first['results']) ?? []);
            $total = $this->toInt($first['total'] ?? null);

            return ['rows' => $rows, 'total' => $total];
        }

        $rows = $this->normalizeRows($data);

        return ['rows' => $rows, 'total' => null];
    }

    /**
     * @param array<int|string, mixed> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        if ([] === $rows) {
            return [];
        }

        if ($this->isAssoc($rows)) {
            return [$rows];
        }

        return $rows;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function isAssoc(array $value): bool
    {
        if ([] === $value) {
            return false;
        }

        return array_keys($value) !== range(0, \count($value) - 1);
    }
}
