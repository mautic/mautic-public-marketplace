<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ZipDownloadMockHttpClient extends MockHttpClient
{
    public function __construct()
    {
        parent::__construct(static function (string $method, string $url): MockResponse {
            if (str_contains($url, 'unreachable.test')) {
                return new MockResponse('', ['error' => 'Could not resolve host: unreachable.test']);
            }

            if (str_contains($url, 'unknown-vendor')) {
                return new MockResponse(
                    self::createValidZip(json_encode([
                        'name' => 'unknown-vendor/test-campaign',
                        'description' => 'A campaign with placeholder vendor.',
                        'type' => 'mautic-resource',
                        'version' => '1.0.0',
                    ])),
                    ['http_code' => 200, 'response_headers' => ['content-type' => 'application/zip']],
                );
            }

            return new MockResponse(
                self::createValidZip(),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/zip']],
            );
        });
    }

    public static function createValidZip(?string $composerJson = null): string
    {
        $composerJson ??= json_encode([
            'name' => 'testuser/test-campaign',
            'description' => 'A test campaign for the marketplace.',
            'type' => 'mautic-resource',
            'version' => '1.0.0',
            'keywords' => ['test', 'campaign'],
            'require' => [
                'mautic/core-lib' => '^5.0',
            ],
            'extra' => [
                'mautic' => [
                    'minimum-version' => '5.0',
                    'campaign-uuid' => 'test-uuid-123',
                    'display-name' => 'Test Campaign',
                ],
            ],
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_zip_');
        if (false === $tmpFile) {
            throw new \RuntimeException('Failed to create temporary file for test ZIP archive.');
        }

        $zip = new \ZipArchive();
        $zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('composer.json', $composerJson);
        $zip->addFromString('entity_data.json', '[]');
        $zip->close();

        $content = file_get_contents($tmpFile);
        unlink($tmpFile);

        if (false === $content) {
            throw new \RuntimeException('Failed to read generated ZIP archive contents.');
        }

        return $content;
    }
}
