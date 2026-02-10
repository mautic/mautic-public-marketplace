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
                return new MockResponse('', ['http_code' => 201]);
            }

            if (str_contains($url, 'get_pack')) {
                return self::packageDetailResponse();
            }

            return self::packageListResponse($url);
        });
    }

    private static function packageListResponse(string $url): MockResponse
    {
        $alphaPlugin = [
            'name' => 'mautic/alpha-plugin',
            'displayname' => 'Alpha Plugin',
            'description' => 'Alpha plugin for sorting.',
            'type' => 'mautic-plugin',
            'repository' => 'https://github.com/mautic/alpha-plugin',
            'downloads' => 100,
            'favers' => 10,
        ];

        $zebraTheme = [
            'name' => 'mautic/zebra-theme',
            'displayname' => 'Zebra Theme',
            'description' => 'A zebra theme.',
            'type' => 'mautic-theme',
            'repository' => 'https://github.com/mautic/zebra-theme',
            'downloads' => 500,
            'favers' => 20,
            'latest_mautic_support' => true,
        ];

        if (str_contains($url, '_type=mautic-theme')) {
            $rows = [$zebraTheme];
        } elseif (str_contains($url, '_orderby=downloads')) {
            $rows = [$zebraTheme, $alphaPlugin];
        } else {
            $rows = [$alphaPlugin, $zebraTheme];
        }

        $data = [['results' => $rows, 'total' => \count($rows)]];

        return new MockResponse(
            json_encode($data),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    private static function packageDetailResponse(): MockResponse
    {
        $data = [
            'package' => [
                'name' => 'mautic/alpha-plugin',
                'displayname' => 'Alpha Plugin',
                'description' => 'Alpha plugin for sorting.',
                'type' => 'mautic-plugin',
                'repository' => 'https://github.com/mautic/alpha-plugin',
                'downloads' => ['total' => 100],
                'favers' => 10,
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
}
