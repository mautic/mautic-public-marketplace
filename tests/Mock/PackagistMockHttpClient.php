<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PackagistMockHttpClient extends MockHttpClient
{
    public function __construct()
    {
        parent::__construct(static function (string $method, string $url, array $options = []): MockResponse {
            if (str_contains($url, 'packagist-down.test')) {
                return new MockResponse('', ['error' => 'Could not resolve host: packagist-down.test']);
            }

            $payload = json_encode([
                'packages' => [
                    'mautic/core-lib' => [
                        ['version' => '7.1.0', 'version_normalized' => '7.1.0.0'],
                        ['version' => '7.0.2', 'version_normalized' => '7.0.2.0'],
                        ['version' => '6.0.4', 'version_normalized' => '6.0.4.0'],
                        ['version' => '5.2.5', 'version_normalized' => '5.2.5.0'],
                        ['version' => '4.4.13', 'version_normalized' => '4.4.13.0'],
                        ['version' => 'dev-main', 'version_normalized' => '9999999-dev'],
                    ],
                ],
            ], \JSON_THROW_ON_ERROR);

            return new MockResponse(
                $payload,
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
            );
        });
    }
}
