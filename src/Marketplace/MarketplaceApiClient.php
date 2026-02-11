<?php

declare(strict_types=1);

namespace App\Marketplace;

use App\Auth0\Auth0Client;
use App\Auth0\Exception\Auth0AuthenticationException;
use App\Marketplace\Dto\PackageDetail;
use App\Marketplace\Dto\PackageListResult;
use App\Marketplace\Dto\PackageSummary;
use App\Marketplace\Dto\ReviewRequest;
use App\Supabase\Exception\SupabaseApiException;
use App\Supabase\SupabaseClient;

final class MarketplaceApiClient
{
    public function __construct(
        private readonly SupabaseClient $supabaseClient,
        private readonly Auth0Client $auth0Client,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Auth0AuthenticationException
     */
    public function validateToken(string $token): array
    {
        return $this->auth0Client->validateToken($token);
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

        $data = $this->supabaseClient->query('GET', '/rest/v1/rpc/get_view', $params);

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

        $data = $this->supabaseClient->query('GET', '/rest/v1/rpc/get_pack', $params);

        if (null === $data || [] === $data) {
            return null;
        }

        $row = $data['package'] ?? null;
        if (!\is_array($row) || !isset($row['name'])) {
            throw new SupabaseApiException('Unexpected response from Supabase get_pack.');
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
        $this->supabaseClient->mutate('POST', '/rest/v1/reviews', [
            'objectId' => $packageName,
            'auth0_user_id' => $userId,
            'user' => $userName,
            'picture' => $picture,
            'rating' => $reviewRequest->rating,
            'review' => $reviewRequest->review,
        ]);
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
