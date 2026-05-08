<?php

declare(strict_types=1);

namespace App\Marketplace;

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
    ) {
    }

    /**
     * @param list<string> $mauticVersions
     * @param list<string> $languages
     */
    public function listPackages(
        int $limit = 10,
        int $offset = 0,
        string $orderBy = 'downloads',
        string $orderDir = 'desc',
        ?string $type = null,
        ?string $query = null,
        array $mauticVersions = [],
        array $languages = [],
        ?int $minimumRating = null,
        bool $unratedOnly = false,
        ?string $ratedBy = null,
        ?string $submittedBy = null,
        ?string $dateRange = null,
        ?string $popularity = null,
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

        if ([] !== $mauticVersions) {
            $params['_smv'] = $mauticVersions;
        }

        if ([] !== $languages) {
            $params['_language'] = $languages;
        }

        if (null !== $minimumRating) {
            $params['_minimum_rating'] = $minimumRating;
        }

        if ($unratedOnly) {
            $params['_unrated_only'] = true;
        }

        if (null !== $ratedBy && '' !== $ratedBy) {
            $params['_rated_by'] = $ratedBy;
        }

        if (null !== $submittedBy && '' !== $submittedBy) {
            $params['_submitted_by'] = $submittedBy;
        }

        if (null !== $dateRange && '' !== $dateRange) {
            $params['_date_range'] = $dateRange;
        }

        if (null !== $popularity && '' !== $popularity) {
            $params['_popularity'] = $popularity;
        }

        $data = $this->supabaseClient->rpc('/rest/v1/rpc/get_view', $params);

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
                $row['validation_errors'] ?? null,
                $this->toDateTime($row['time'] ?? null),
            );
        }

        return new PackageListResult($items, $limit, $offset, $total);
    }

    /**
     * @param list<string> $mauticVersions
     *
     * @return list<string>
     */
    public function getAvailableLanguages(
        ?string $type = null,
        ?string $query = null,
        array $mauticVersions = [],
        ?int $minimumRating = null,
        bool $unratedOnly = false,
        ?string $ratedBy = null,
        ?string $submittedBy = null,
        ?string $dateRange = null,
        ?string $popularity = null,
    ): array {
        $params = [];

        if (null !== $query && '' !== $query) {
            $params['_query'] = $query;
        }

        if (null !== $type && '' !== $type) {
            $params['_type'] = $type;
        }

        if ([] !== $mauticVersions) {
            $params['_smv'] = $mauticVersions;
        }

        if (null !== $minimumRating) {
            $params['_minimum_rating'] = $minimumRating;
        }

        if ($unratedOnly) {
            $params['_unrated_only'] = true;
        }

        if (null !== $ratedBy && '' !== $ratedBy) {
            $params['_rated_by'] = $ratedBy;
        }

        if (null !== $submittedBy && '' !== $submittedBy) {
            $params['_submitted_by'] = $submittedBy;
        }

        if (null !== $dateRange && '' !== $dateRange) {
            $params['_date_range'] = $dateRange;
        }

        if (null !== $popularity && '' !== $popularity) {
            $params['_popularity'] = $popularity;
        }

        $data = $this->supabaseClient->rpc('/rest/v1/rpc/get_available_languages', $params);

        if (!\is_array($data)) {
            return [];
        }

        $values = [];
        foreach ($data as $value) {
            if (\is_string($value) && '' !== trim($value)) {
                $values[] = trim($value);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @return list<string>
     */
    public function getCompatibleMauticVersions(): array
    {
        $data = $this->supabaseClient->rpc('/rest/v1/rpc/get_compatible_mautic_versions', []);

        if (!\is_array($data)) {
            return [];
        }

        $values = [];
        foreach ($data as $value) {
            if (\is_string($value) && '' !== $value) {
                $values[] = $value;
            }
        }

        $values = array_values(array_unique($values));
        sort($values);

        return $values;
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

        $versions = $this->toArray($row['versions'] ?? null);
        $tags = $this->toArray($row['tags'] ?? $row['keywords'] ?? null);
        if (null === $tags && null !== $versions) {
            $keywords = [];
            foreach ($versions as $version) {
                if (!\is_array($version) || !\is_array($version['keywords'] ?? null)) {
                    continue;
                }

                foreach ($version['keywords'] as $keyword) {
                    if (\is_scalar($keyword)) {
                        $keywords[] = (string) $keyword;
                    }
                }
            }

            $keywords = array_values(array_unique($keywords));
            $tags = [] === $keywords ? null : $keywords;
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
            $tags,
            isset($row['time']) ? (string) $row['time'] : null,
            $this->toArray($row['reviews'] ?? null),
            $versions,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUserReviews(string $auth0UserId): array
    {
        $data = $this->supabaseClient->query('GET', '/rest/v1/reviews', [
            'auth0_user_id' => 'eq.'.$auth0UserId,
            'select' => '*',
            'order' => 'created_at.desc',
        ]);

        if (!\is_array($data)) {
            return [];
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUserUploadedPackages(string $auth0UserId): array
    {
        $data = $this->supabaseClient->query('GET', '/rest/v1/packages', [
            'auth0_user_id' => 'eq.'.$auth0UserId,
            'select' => 'name,displayname,description,type,downloads,favers',
            'order' => 'name.asc',
        ]);

        if (!\is_array($data)) {
            return [];
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUserDownloadHistory(string $auth0UserId): array
    {
        $data = $this->supabaseClient->queryPrivate('GET', '/rest/v1/download_history', [
            'auth0_user_id' => 'eq.'.$auth0UserId,
            'select' => 'id,package_name,package_version,downloaded_at,package:packages(name,displayname,description,type)',
            'order' => 'downloaded_at.desc',
        ]);

        if (!\is_array($data)) {
            return [];
        }

        return $data;
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
