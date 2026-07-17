<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SupabaseMockHttpClient extends MockHttpClient
{
    /**
     * Bodies POSTed to /rest/v1/download_history, captured so tests can assert
     * that a download was recorded (and for whom).
     *
     * @var list<array<string, mixed>>
     */
    public array $recordedDownloads = [];

    public function __construct()
    {
        parent::__construct(function (string $method, string $url, array $options = []): MockResponse {
            if ('POST' === $method && str_contains($url, '/rest/v1/download_history')) {
                $decoded = json_decode((string) ($options['body'] ?? ''), true);
                $this->recordedDownloads[] = \is_array($decoded) ? $decoded : [];

                return new MockResponse('[]', ['http_code' => 201, 'response_headers' => ['content-type' => 'application/json']]);
            }

            if ('GET' === $method && str_contains($url, '/storage/v1/object/public/')) {
                return self::storageObjectResponse($url);
            }

            if (str_contains($url, '/storage/v1/object/')) {
                return new MockResponse(
                    json_encode(['Key' => 'package-media/mock-object']),
                    ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
                );
            }

            if ('PATCH' === $method && str_contains($url, '/rest/v1/packages')) {
                return new MockResponse('[]', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
            }

            if ('POST' === $method && !str_contains($url, '/rpc/')) {
                return new MockResponse('[]', ['http_code' => 201, 'response_headers' => ['content-type' => 'application/json']]);
            }

            if ('PATCH' === $method) {
                return new MockResponse('[]', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
            }

            // GET /rest/v1/packages?name=eq.xxx — package lookup by name
            if (str_contains($url, '/rest/v1/packages') && str_contains($url, 'name=eq.')) {
                return self::packageByNameResponse($url);
            }
            if ('GET' === $method && str_contains($url, '/rest/v1/reviews') && !str_contains($url, '/rpc/')) {
                return self::userReviewsResponse($url);
            }

            if ('GET' === $method && str_contains($url, '/rest/v1/download_history') && !str_contains($url, '/rpc/')) {
                return self::downloadHistoryResponse($url);
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

            if (str_contains($url, 'get_available_languages')) {
                return self::availableLanguagesResponse($method, $options);
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
                'language' => 'en',
                'keywords' => ['example', 'plugin', 'local-development'],
                'average_rating' => 4.7,
                'total_review' => 2,
                'auth0_user_id' => 'auth0|test123',
            ],
            'mautic/alpha-plugin' => [
                'name' => 'mautic/alpha-plugin',
                'displayname' => 'Alpha Plugin',
                'description' => 'Alpha plugin for sorting.',
                'type' => 'mautic-plugin',
                'repository' => 'https://github.com/mautic/alpha-plugin',
                'downloads' => 10,
                'favers' => 2,
                'time' => (new \DateTimeImmutable('first day of this month -2 months'))->format('c'),
                'maintainers' => 'rcheesley',
                'smv' => '^4.3 || ^5.0',
                'language' => 'English',
                'keywords' => ['alpha', 'plugin', 'sorting'],
                'average_rating' => 3.0,
                'total_review' => 1,
                'auth0_user_id' => null,
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
                'language' => 'nl',
                'keywords' => ['theme', 'zebra', 'responsive'],
                'average_rating' => 4.0,
                'total_review' => 1,
                'auth0_user_id' => 'auth0|other',
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
                'language' => 'Nederlands',
                'keywords' => ['campaign', 'welcome', 'resource', 'automation'],
                'average_rating' => 0,
                'total_review' => 0,
                'auth0_user_id' => 'auth0|test123',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function packageListResponse(string $url, string $method, array $options): MockResponse
    {
        $params = self::parseParams($url, $method, $options);
        $rows = array_values(self::allPackages());

        // Filter by type
        if (isset($params['_type']) && '' !== $params['_type']) {
            $type = $params['_type'];
            $rows = array_values(array_filter($rows, static fn (array $r): bool => ($r['type'] ?? '') === $type));
        }

        if (isset($params['_query']) && '' !== $params['_query']) {
            $q = strtolower($params['_query']);
            $rows = array_values(array_filter($rows, static function (array $r) use ($q): bool {
                $keywords = \is_array($r['keywords'] ?? null) ? implode(' ', $r['keywords']) : '';

                return str_contains(strtolower($r['name']), $q)
                    || str_contains(strtolower($r['maintainers'] ?? ''), $q)
                    || str_contains(strtolower($keywords), $q);
            }));
        }

        // Filter by SMV
        $selectedVersions = self::normalizeSelectedVersions($params['_smv'] ?? null);
        if ([] !== $selectedVersions) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => [] !== array_intersect(
                self::splitSmvValues($r['smv'] ?? null),
                $selectedVersions,
            )));
        }

        $selectedLanguages = self::normalizeSelectedLanguages($params['_language'] ?? null);
        if ([] !== $selectedLanguages) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => \in_array(
                self::canonicalizeLanguage($r['language'] ?? null),
                $selectedLanguages,
                true,
            )));
        }

        $minimumRating = isset($params['_minimum_rating']) && is_numeric($params['_minimum_rating']) ? (int) $params['_minimum_rating'] : null;
        if (null !== $minimumRating) {
            $threshold = 5 === $minimumRating ? 4.6 : $minimumRating;
            $rows = array_values(array_filter($rows, static fn (array $r): bool => ($r['average_rating'] ?? 0) >= $threshold));
        }

        if (self::isTruthy($params['_unrated_only'] ?? false)) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => 0 === ($r['total_review'] ?? 0)));
        }

        $ratedBy = isset($params['_rated_by']) && \is_string($params['_rated_by']) ? trim($params['_rated_by']) : null;
        if (null !== $ratedBy && '' !== $ratedBy) {
            $reviewedObjectIds = array_column(array_filter(self::allReviews(), static fn (array $review): bool => $review['auth0_user_id'] === $ratedBy), 'objectId');
            $rows = array_values(array_filter($rows, static fn (array $r): bool => \in_array($r['name'], $reviewedObjectIds, true)));
        }

        $submittedBy = isset($params['_submitted_by']) && \is_string($params['_submitted_by']) ? trim($params['_submitted_by']) : null;
        if (null !== $submittedBy && '' !== $submittedBy) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => ($r['auth0_user_id'] ?? null) === $submittedBy));
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

    /**
     * @param array<string, mixed> $options
     */
    private static function availableLanguagesResponse(string $method, array $options): MockResponse
    {
        $params = self::parseParams('', $method, $options);
        $rows = self::filteredRowsForAvailableLanguages($params);
        $languages = [];

        foreach ($rows as $row) {
            $language = $row['language'] ?? null;
            if (\is_string($language) && '' !== trim($language)) {
                $languages[] = trim($language);
            }
        }

        return new MockResponse(
            json_encode(array_values(array_unique($languages))),
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
                'language' => $pkg['language'],
                'versions' => [
                    '1.0.0' => [
                        'version' => '1.0.0',
                        'smv' => $pkg['smv'],
                        'dist' => [
                            'type' => 'zip',
                            'url' => 'https://storage.example.test/package-media/dist/'.str_replace('/', '_', (string) $pkg['name']).'/1.0.0.zip',
                        ],
                    ],
                ],
                'reviews' => [],
                'maintainers' => [],
            ],
        ];

        if ('mautic/welcome-campaign' === $packageName) {
            // Marketplace-hosted archive with presentation images and two versions;
            // 1.0.0 deliberately listed first to prove the newest one is served.
            $distBase = 'http://127.0.0.1:8000/storage/v1/object/public/package-media/dist/mautic_welcome-campaign/';
            $data['package']['versions'] = [
                '1.0.0' => ['version' => '1.0.0', 'smv' => $pkg['smv'], 'dist' => ['type' => 'zip', 'url' => $distBase.'1.0.0.zip']],
                '1.0.1' => ['version' => '1.0.1', 'smv' => $pkg['smv'], 'dist' => ['type' => 'zip', 'url' => $distBase.'1.0.1.zip']],
            ];
            $data['package']['banner_url'] = 'package-media/banners/mautic_welcome-campaign/banner.png';
            $data['package']['gallery'] = [
                ['url' => 'package-media/gallery/mautic_welcome-campaign/image_1.png', 'alt' => 'Campaign canvas'],
            ];
        }

        return new MockResponse(
            json_encode($data),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    /**
     * Serves public storage objects the download flow fetches server-side: a real
     * ZIP archive for dist objects and raw bytes for banner/gallery images.
     */
    private static function storageObjectResponse(string $url): MockResponse
    {
        $path = (string) (parse_url($url, \PHP_URL_PATH) ?: '');

        if (str_ends_with($path, '.zip')) {
            $tmpPath = (string) tempnam(sys_get_temp_dir(), 'mock-dist-');
            $zip = new \ZipArchive();
            $zip->open($tmpPath, \ZipArchive::OVERWRITE);
            $zip->addFromString('entity_data.json', '[{"campaigns": []}]');
            $zip->addFromString('composer.json', (string) json_encode(['name' => 'mautic/welcome-campaign', 'version' => '1.0.1']));
            $zip->close();
            $contents = (string) file_get_contents($tmpPath);
            @unlink($tmpPath);

            return new MockResponse($contents, ['http_code' => 200, 'response_headers' => ['content-type' => 'application/zip']]);
        }

        return new MockResponse('mock-image-bytes', ['http_code' => 200, 'response_headers' => ['content-type' => 'image/png']]);
    }

    private static function userReviewsResponse(string $url): MockResponse
    {
        $params = self::parseParams($url, 'GET', []);
        $userId = $params['auth0_user_id'] ?? '';
        $userId = str_replace('eq.', '', $userId);

        $allReviews = self::allReviews();

        $filtered = array_values(array_filter($allReviews, static fn (array $r): bool => $r['auth0_user_id'] === $userId));

        return new MockResponse(
            json_encode($filtered),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function allReviews(): array
    {
        return [
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
    }

    private static function packageByNameResponse(string $url): MockResponse
    {
        $params = self::parseParams($url, 'GET', []);
        $nameFilter = $params['name'] ?? '';
        $packageName = str_replace('eq.', '', (string) $nameFilter);

        $existing = [
            'mautic/example-plugin' => [
                'name' => 'mautic/example-plugin',
                'displayname' => 'Example Plugin',
                'description' => 'Example package for local development.',
                'type' => 'mautic-plugin',
                'auth0_user_id' => 'auth0|test123',
                'status' => 'published',
                'time' => '2026-01-01T00:00:00+00:00',
            ],
            'mautic/zebra-theme' => [
                'name' => 'mautic/zebra-theme',
                'displayname' => 'Zebra Theme',
                'auth0_user_id' => 'auth0|other',
                'status' => 'published',
                'time' => '2026-01-01T00:00:00+00:00',
            ],
        ];

        if (isset($existing[$packageName])) {
            return new MockResponse(
                json_encode([$existing[$packageName]]),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
            );
        }

        return new MockResponse(
            '[]',
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    private static function userPackagesResponse(string $url): MockResponse
    {
        $params = self::parseParams($url, 'GET', []);
        $userId = $params['auth0_user_id'] ?? '';
        $userId = str_replace('eq.', '', $userId);

        $allPackages = [
            [
                'name' => 'mautic/example-plugin',
                'displayname' => 'Example Plugin',
                'description' => 'Example package for local development.',
                'type' => 'mautic-plugin',
                'downloads' => 1234,
                'favers' => 10,
                'auth0_user_id' => 'auth0|test123',
            ],
            [
                'name' => 'mautic/welcome-campaign',
                'displayname' => 'Welcome Campaign',
                'description' => 'Welcome drip campaign resource template.',
                'type' => 'mautic-resource',
                'downloads' => 500,
                'favers' => 3,
                'auth0_user_id' => 'auth0|test123',
            ],
            [
                'name' => 'mautic/zebra-theme',
                'displayname' => 'Zebra Theme',
                'description' => 'Zebra theme for sorting.',
                'type' => 'mautic-theme',
                'downloads' => 5000,
                'favers' => 5,
                'auth0_user_id' => 'auth0|other',
            ],
        ];

        $filtered = array_values(array_filter($allPackages, static fn (array $package): bool => $package['auth0_user_id'] === $userId));

        return new MockResponse(
            json_encode($filtered),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    private static function downloadHistoryResponse(string $url): MockResponse
    {
        $params = self::parseParams($url, 'GET', []);
        $userId = $params['auth0_user_id'] ?? '';
        $userId = str_replace('eq.', '', $userId);
        $packages = self::allPackages();
        $allHistory = [
            [
                'id' => 1,
                'auth0_user_id' => 'auth0|test123',
                'package_name' => 'mautic/alpha-plugin',
                'package_version' => '1.0.0',
                'downloaded_at' => (new \DateTimeImmutable('-1 day'))->format('c'),
                'package' => [
                    'name' => $packages['mautic/alpha-plugin']['name'],
                    'displayname' => $packages['mautic/alpha-plugin']['displayname'],
                    'description' => $packages['mautic/alpha-plugin']['description'],
                    'type' => $packages['mautic/alpha-plugin']['type'],
                ],
            ],
            [
                'id' => 2,
                'auth0_user_id' => 'auth0|test123',
                'package_name' => 'mautic/example-plugin',
                'package_version' => null,
                'downloaded_at' => (new \DateTimeImmutable('-4 days'))->format('c'),
                'package' => [
                    'name' => $packages['mautic/example-plugin']['name'],
                    'displayname' => $packages['mautic/example-plugin']['displayname'],
                    'description' => $packages['mautic/example-plugin']['description'],
                    'type' => $packages['mautic/example-plugin']['type'],
                ],
            ],
            [
                'id' => 3,
                'auth0_user_id' => 'auth0|other',
                'package_name' => 'mautic/zebra-theme',
                'package_version' => null,
                'downloaded_at' => (new \DateTimeImmutable('-2 days'))->format('c'),
                'package' => [
                    'name' => $packages['mautic/zebra-theme']['name'],
                    'displayname' => $packages['mautic/zebra-theme']['displayname'],
                    'description' => $packages['mautic/zebra-theme']['description'],
                    'type' => $packages['mautic/zebra-theme']['type'],
                ],
            ],
        ];

        $filtered = array_values(array_filter($allHistory, static fn (array $historyItem): bool => $historyItem['auth0_user_id'] === $userId));

        usort($filtered, static fn (array $a, array $b): int => strtotime($b['downloaded_at']) <=> strtotime($a['downloaded_at']));

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
     * @param array<string, mixed> $options
     *
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

    /**
     * @param array<string, mixed> $params
     *
     * @return array<int, array<string, mixed>>
     */
    private static function filteredRowsForAvailableLanguages(array $params): array
    {
        $rows = array_values(self::allPackages());

        if (isset($params['_type']) && '' !== $params['_type']) {
            $type = $params['_type'];
            $rows = array_values(array_filter($rows, static fn (array $r): bool => ($r['type'] ?? '') === $type));
        }

        if (isset($params['_query']) && '' !== $params['_query']) {
            $q = strtolower($params['_query']);
            $rows = array_values(array_filter($rows, static function (array $r) use ($q): bool {
                $keywords = \is_array($r['keywords'] ?? null) ? implode(' ', $r['keywords']) : '';

                return str_contains(strtolower($r['name']), $q)
                    || str_contains(strtolower($r['maintainers'] ?? ''), $q)
                    || str_contains(strtolower($keywords), $q);
            }));
        }

        $selectedVersions = self::normalizeSelectedVersions($params['_smv'] ?? null);
        if ([] !== $selectedVersions) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => [] !== array_intersect(
                self::splitSmvValues($r['smv'] ?? null),
                $selectedVersions,
            )));
        }

        $minimumRating = isset($params['_minimum_rating']) && is_numeric($params['_minimum_rating']) ? (int) $params['_minimum_rating'] : null;
        if (null !== $minimumRating) {
            $threshold = 5 === $minimumRating ? 4.6 : $minimumRating;
            $rows = array_values(array_filter($rows, static fn (array $r): bool => ($r['average_rating'] ?? 0) >= $threshold));
        }

        if (self::isTruthy($params['_unrated_only'] ?? false)) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => 0 === ($r['total_review'] ?? 0)));
        }

        $ratedBy = isset($params['_rated_by']) && \is_string($params['_rated_by']) ? trim($params['_rated_by']) : null;
        if (null !== $ratedBy && '' !== $ratedBy) {
            $reviewedObjectIds = array_column(array_filter(self::allReviews(), static fn (array $review): bool => $review['auth0_user_id'] === $ratedBy), 'objectId');
            $rows = array_values(array_filter($rows, static fn (array $r): bool => \in_array($r['name'], $reviewedObjectIds, true)));
        }

        $submittedBy = isset($params['_submitted_by']) && \is_string($params['_submitted_by']) ? trim($params['_submitted_by']) : null;
        if (null !== $submittedBy && '' !== $submittedBy) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => ($r['auth0_user_id'] ?? null) === $submittedBy));
        }

        $dateRange = $params['_date_range'] ?? null;
        if (null !== $dateRange && '' !== $dateRange) {
            $rows = self::filterByDateRange($rows, $dateRange);
        }

        $popularity = $params['_popularity'] ?? null;
        if ('rising' === $popularity) {
            $rows = self::filterByDateRange($rows, '30d');
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private static function normalizeSelectedLanguages(mixed $value): array
    {
        if (\is_string($value)) {
            $value = [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        $languages = [];
        foreach ($value as $item) {
            $canonical = self::canonicalizeLanguage($item);
            if (null === $canonical) {
                continue;
            }

            $languages[] = $canonical;
        }

        return array_values(array_unique($languages));
    }

    private static function canonicalizeLanguage(mixed $language): ?string
    {
        if (!\is_string($language)) {
            return null;
        }

        $language = strtolower(trim($language));
        if ('' === $language) {
            return null;
        }

        return match ($language) {
            'en', 'en-us', 'en-gb', 'english' => 'english',
            'nl', 'nl-nl', 'dutch', 'nederlands' => 'dutch',
            default => $language,
        };
    }

    private static function isTruthy(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value || 'true' === $value;
    }
}
