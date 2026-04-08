<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SupabaseMockHttpClient extends MockHttpClient
{
    public function __construct()
    {
        parent::__construct(static function (string $method, string $url, array $options = []): MockResponse {
            if ('POST' === $method && !str_contains($url, '/rpc/')) {
                return new MockResponse('[]', ['http_code' => 201, 'response_headers' => ['content-type' => 'application/json']]);
            }

            if ('GET' === $method && str_contains($url, '/rest/v1/reviews') && !str_contains($url, '/rpc/')) {
                return self::userReviewsResponse($url);
            }

            if ('GET' === $method && str_contains($url, '/rest/v1/packages') && !str_contains($url, '/rpc/')) {
                return self::userPackagesResponse($url);
            }

            if (str_contains($url, 'get_pack')) {
                return self::packageDetailResponse($url);
            }

            if (str_contains($url, 'get_compatible_mautic_versions')) {
                return self::compatibleMauticVersionsResponse();
            }

            return self::packageListResponse($url, $method, $options);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function allPackages(): array
    {
        return [
            'mautic/example-plugin' => [
                'name' => 'mautic/example-plugin',
                'displayname' => 'Example Plugin',
                'description' => 'Example package for local development.',
                'type' => 'mautic-plugin',
                'repository' => 'https://github.com/mautic/example-plugin',
                'downloads' => 1234,
                'favers' => 10,
                'time' => (new \DateTimeImmutable('-5 days'))->format('c'),
                'maintainers' => 'escopecz',
                'smv' => '^5.0 || ^5.1',
                'average_rating' => 0,
                'total_review' => 0,
            ],
            'mautic/alpha-plugin' => [
                'name' => 'mautic/alpha-plugin',
                'displayname' => 'Alpha Plugin',
                'description' => 'Alpha plugin for sorting.',
                'type' => 'mautic-plugin',
                'repository' => 'https://github.com/mautic/alpha-plugin',
                'downloads' => 10,
                'favers' => 2,
                'time' => (new \DateTimeImmutable('-60 days'))->format('c'),
                'maintainers' => 'rcheesley',
                'smv' => '^4.3 || ^5.0',
                'average_rating' => 0,
                'total_review' => 0,
            ],
            'mautic/zebra-theme' => [
                'name' => 'mautic/zebra-theme',
                'displayname' => 'Zebra Theme',
                'description' => 'Zebra theme for sorting.',
                'type' => 'mautic-theme',
                'repository' => 'https://github.com/mautic/zebra-theme',
                'downloads' => 5000,
                'favers' => 5,
                'time' => (new \DateTimeImmutable('-200 days'))->format('c'),
                'maintainers' => 'escopecz',
                'smv' => '^4.4 || ^5.0 || ^5.2',
                'average_rating' => 0,
                'total_review' => 0,
            ],
            'mautic/welcome-campaign' => [
                'name' => 'mautic/welcome-campaign',
                'displayname' => 'Welcome Campaign',
                'description' => 'Welcome drip campaign resource template.',
                'type' => 'mautic-resource',
                'repository' => 'https://github.com/mautic/welcome-campaign',
                'downloads' => 500,
                'favers' => 3,
                'time' => (new \DateTimeImmutable('-10 days'))->format('c'),
                'maintainers' => 'rcheesley',
                'smv' => '^4.2 || ^5.3',
                'average_rating' => 0,
                'total_review' => 0,
            ],
        ];
    }

    private static function packageListResponse(string $url, string $method, array $options): MockResponse
    {
        $params = self::parseParams($url, $method, $options);
        $rows = array_values(self::allPackages());

        // Filter by type
        if (isset($params['_type']) && '' !== $params['_type']) {
            $type = $params['_type'];
            $rows = array_values(array_filter($rows, static fn (array $r): bool => ($r['type'] ?? '') === $type));
        }

        // Filter by query (searches both name and maintainers)
        if (isset($params['_query']) && '' !== $params['_query']) {
            $q = strtolower($params['_query']);
            $rows = array_values(array_filter($rows, static fn (array $r): bool => str_contains(strtolower($r['name']), $q)
                || str_contains(strtolower($r['maintainers'] ?? ''), $q)));
        }

        // Filter by SMV
        $selectedVersions = self::normalizeSelectedVersions($params['_smv'] ?? null);
        if ([] !== $selectedVersions) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => [] !== array_intersect(
                self::splitSmvValues($r['smv'] ?? null),
                $selectedVersions,
            )));
        }

        // Filter by date range
        $dateRange = $params['_date_range'] ?? null;
        if (null !== $dateRange && '' !== $dateRange) {
            $rows = self::filterByDateRange($rows, $dateRange);
        }

        // Handle popularity presets (override sort)
        $orderBy = $params['_orderby'] ?? 'downloads';
        $orderDir = $params['_orderdir'] ?? 'desc';
        $popularity = $params['_popularity'] ?? null;

        if ('most_popular' === $popularity) {
            $orderBy = 'downloads';
            $orderDir = 'desc';
        } elseif ('newest' === $popularity) {
            $orderBy = 'time';
            $orderDir = 'desc';
        } elseif ('rising' === $popularity) {
            $orderBy = 'downloads';
            $orderDir = 'desc';
            $rows = self::filterByDateRange($rows, '30d');
        }

        // Sort
        usort($rows, static function (array $a, array $b) use ($orderBy, $orderDir): int {
            if ('name' === $orderBy) {
                $cmp = strcasecmp($a['displayname'] ?? $a['name'], $b['displayname'] ?? $b['name']);
            } elseif ('time' === $orderBy) {
                $cmp = strtotime($a['time'] ?? '0') <=> strtotime($b['time'] ?? '0');
            } else {
                $cmp = ($a['downloads'] ?? 0) <=> ($b['downloads'] ?? 0);
            }

            return 'asc' === $orderDir ? $cmp : -$cmp;
        });

        $total = \count($rows);

        // Pagination
        $limit = isset($params['_limit']) ? (int) $params['_limit'] : 10;
        $offset = isset($params['_offset']) ? (int) $params['_offset'] : 0;
        $rows = \array_slice($rows, $offset, $limit);

        $data = [['results' => $rows, 'total' => $total]];

        return new MockResponse(
            json_encode($data),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    private static function compatibleMauticVersionsResponse(): MockResponse
    {
        $versions = [];

        foreach (self::allPackages() as $package) {
            $versions = [...$versions, ...self::splitSmvValues($package['smv'] ?? null)];
        }

        $versions = array_values(array_unique($versions));
        sort($versions);

        return new MockResponse(
            json_encode($versions),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    private static function packageDetailResponse(string $url): MockResponse
    {
        $params = self::parseParams($url, 'GET', []);
        $packageName = $params['packag_name'] ?? '';

        $all = self::allPackages();
        $pkg = $all[$packageName] ?? $all['mautic/alpha-plugin'];

        $data = [
            'package' => [
                'name' => $pkg['name'],
                'displayname' => $pkg['displayname'],
                'description' => $pkg['description'],
                'type' => $pkg['type'],
                'repository' => $pkg['repository'],
                'downloads' => ['total' => $pkg['downloads']],
                'favers' => $pkg['favers'],
                'time' => $pkg['time'],
                'versions' => [
                    '1.0.0' => [
                        'smv' => $pkg['smv'],
                    ],
                ],
                'reviews' => [],
                'maintainers' => [],
            ],
        ];

        return new MockResponse(
            json_encode($data),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    private static function userReviewsResponse(string $url): MockResponse
    {
        $params = self::parseParams($url, 'GET', []);
        $userId = $params['auth0_user_id'] ?? '';
        $userId = str_replace('eq.', '', $userId);

        $allReviews = [
            [
                'id' => 1,
                'objectId' => 'mautic/example-plugin',
                'auth0_user_id' => 'auth0|test123',
                'user' => 'Test User',
                'rating' => 5,
                'review' => 'Great plugin!',
                'picture' => null,
                'created_at' => (new \DateTimeImmutable('-2 days'))->format('c'),
            ],
            [
                'id' => 2,
                'objectId' => 'mautic/zebra-theme',
                'auth0_user_id' => 'auth0|test123',
                'user' => 'Test User',
                'rating' => 4,
                'review' => 'Nice theme.',
                'picture' => null,
                'created_at' => (new \DateTimeImmutable('-5 days'))->format('c'),
            ],
            [
                'id' => 3,
                'objectId' => 'mautic/alpha-plugin',
                'auth0_user_id' => 'auth0|other',
                'user' => 'Other User',
                'rating' => 3,
                'review' => 'OK plugin.',
                'picture' => null,
                'created_at' => (new \DateTimeImmutable('-1 day'))->format('c'),
            ],
        ];

        $filtered = array_values(array_filter($allReviews, static fn (array $r): bool => $r['auth0_user_id'] === $userId));

        return new MockResponse(
            json_encode($filtered),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    private static function userPackagesResponse(string $url): MockResponse
    {
        $params = self::parseParams($url, 'GET', []);
        $maintainersFilter = $params['maintainers'] ?? '';

        $allPackages = self::allPackages();

        // Parse the cs. filter: cs.[{"name":"value"}]
        $matchName = null;
        if (preg_match('/cs\.\[\{"name":"([^"]+)"\}\]/', $maintainersFilter, $matches)) {
            $matchName = $matches[1];
        }

        if (null === $matchName) {
            return new MockResponse(
                json_encode([]),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
            );
        }

        $filtered = [];
        foreach ($allPackages as $pkg) {
            if (strcasecmp($pkg['maintainers'], $matchName) === 0) {
                $filtered[] = [
                    'name' => $pkg['name'],
                    'displayname' => $pkg['displayname'],
                    'description' => $pkg['description'],
                    'type' => $pkg['type'],
                    'downloads' => $pkg['downloads'],
                    'favers' => $pkg['favers'],
                ];
            }
        }

        return new MockResponse(
            json_encode($filtered),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private static function filterByDateRange(array $rows, string $range): array
    {
        $days = match ($range) {
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            '365d' => 365,
            default => null,
        };

        if (null === $days) {
            return $rows;
        }

        $cutoff = (new \DateTimeImmutable("-{$days} days"))->getTimestamp();

        return array_values(array_filter($rows, static fn (array $r): bool => strtotime($r['time'] ?? '0') >= $cutoff));
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseParams(string $url, string $method, array $options): array
    {
        if ('POST' === $method) {
            $body = $options['body'] ?? null;
            if (\is_string($body) && '' !== $body) {
                $decoded = json_decode($body, true);

                return \is_array($decoded) ? $decoded : [];
            }

            return [];
        }

        $parts = parse_url($url);
        $query = $parts['query'] ?? '';
        $params = [];
        parse_str($query, $params);

        return $params;
    }

    /**
     * @return list<string>
     */
    private static function normalizeSelectedVersions(mixed $value): array
    {
        if (\is_string($value)) {
            $value = [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => \is_string($item) && '' !== $item));
    }

    /**
     * @return list<string>
     */
    private static function splitSmvValues(mixed $value): array
    {
        if (!\is_string($value) || '' === $value) {
            return [];
        }

        $parts = preg_split('/\s*\|\|\s*/', $value) ?: [];
        $parts = array_map(static fn (string $part): string => trim($part), $parts);

        return array_values(array_filter($parts, static fn (string $part): bool => '' !== $part));
    }
}
