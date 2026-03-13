<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SupabaseMockHttpClient extends MockHttpClient
{
    public function __construct()
    {
        parent::__construct(static function (string $method, string $url): MockResponse {
            if ('POST' === $method) {
                return new MockResponse('[]', ['http_code' => 201, 'response_headers' => ['content-type' => 'application/json']]);
            }

            if (str_contains($url, 'get_pack')) {
                return self::packageDetailResponse($url);
            }

            return self::packageListResponse($url);
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
                'smv' => '^5.0',
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
                'smv' => '^5.0',
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
                'smv' => '^4.4 || ^5.0',
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
                'smv' => '^5.0',
                'average_rating' => 0,
                'total_review' => 0,
            ],
        ];
    }

    private static function packageListResponse(string $url): MockResponse
    {
        $params = self::parseQueryParams($url);
        $rows = array_values(self::allPackages());

        // Filter by type
        if (isset($params['_type']) && '' !== $params['_type']) {
            $type = $params['_type'];
            $rows = array_values(array_filter($rows, static fn (array $r) => ($r['type'] ?? '') === $type));
        }

        // Filter by query (searches both name and maintainers)
        if (isset($params['_query']) && '' !== $params['_query']) {
            $q = strtolower($params['_query']);
            $rows = array_values(array_filter($rows, static fn (array $r) => str_contains(strtolower($r['name']), $q)
                || str_contains(strtolower($r['maintainers'] ?? ''), $q)));
        }

        // Filter by SMV
        if (isset($params['_smv']) && '' !== $params['_smv']) {
            $smv = $params['_smv'];
            $rows = array_values(array_filter($rows, static fn (array $r) => str_contains($r['smv'] ?? '', $smv)));
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

    private static function packageDetailResponse(string $url): MockResponse
    {
        $params = self::parseQueryParams($url);
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
                'versions' => [],
                'reviews' => [],
                'maintainers' => [],
            ],
        ];

        return new MockResponse(
            json_encode($data),
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

        return array_values(array_filter($rows, static fn (array $r) => strtotime($r['time'] ?? '0') >= $cutoff));
    }

    /**
     * @return array<string, string>
     */
    private static function parseQueryParams(string $url): array
    {
        $parts = parse_url($url);
        $query = $parts['query'] ?? '';
        $params = [];
        parse_str($query, $params);

        return $params;
    }
}
