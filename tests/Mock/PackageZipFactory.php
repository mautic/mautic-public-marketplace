<?php

declare(strict_types=1);

namespace App\Tests\Mock;

final class PackageZipFactory
{
    /**
     * @param array<string, mixed>|null $composer Override composer.json contents (null = default valid composer).
     */
    public static function create(?array $composer = null, bool $omitComposer = false, bool $invalidJson = false): string
    {
        $composer ??= [
            'name' => 'testuser/test-campaign',
            'description' => 'A test campaign for the marketplace.',
            'type' => 'mautic-resource',
            'version' => '1.0.0',
            'keywords' => ['test', 'campaign'],
            'license' => 'MIT',
            'require' => ['mautic/core-lib' => '^5.0'],
            'extra' => ['mautic' => ['minimum-version' => '5.0']],
        ];

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_zip_');
        if (false === $tmpFile) {
            throw new \RuntimeException('Failed to create temporary ZIP file.');
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Failed to open temporary ZIP file.');
        }
        if (!$omitComposer) {
            $zip->addFromString('composer.json', $invalidJson ? '{not-json' : json_encode($composer, \JSON_THROW_ON_ERROR));
        }
        $zip->addFromString('README.md', '# Test');
        $zip->close();

        return $tmpFile;
    }
}
