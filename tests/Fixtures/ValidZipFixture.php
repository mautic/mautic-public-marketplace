<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

final class ValidZipFixture
{
    /**
     * Builds an in-memory ZIP with the given composer.json (defaults to a valid Mautic resource).
     * Used by upload-endpoint tests to simulate what Mautic's share flow would produce.
     *
     * @param array<string, string> $extraFiles additional ZIP entries (e.g. ['banner.png' => $bytes])
     */
    public static function build(?string $composerJson = null, array $extraFiles = []): string
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
        foreach ($extraFiles as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        $content = file_get_contents($tmpFile);
        unlink($tmpFile);

        if (false === $content) {
            throw new \RuntimeException('Failed to read generated ZIP archive contents.');
        }

        return $content;
    }
}
