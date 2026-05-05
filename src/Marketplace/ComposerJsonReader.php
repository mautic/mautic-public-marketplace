<?php

declare(strict_types=1);

namespace App\Marketplace;

use App\Marketplace\Exception\SubmitValidationException;

final class ComposerJsonReader
{
    private const ALLOWED_TYPES = ['mautic-plugin', 'mautic-theme', 'mautic-resource'];

    /**
     * @return array<string, mixed>
     */
    public function read(string $zipPath): array
    {
        $zip = new \ZipArchive();
        $result = $zip->open($zipPath);

        if (true !== $result) {
            throw new SubmitValidationException('The uploaded file is not a valid ZIP archive.');
        }

        try {
            $composerJson = $zip->getFromName('composer.json');

            if (false === $composerJson) {
                for ($i = 0; $i < $zip->numFiles; ++$i) {
                    $name = $zip->getNameIndex($i);
                    if (false !== $name && str_ends_with($name, '/composer.json') && 1 === substr_count($name, '/')) {
                        $composerJson = $zip->getFromIndex($i);
                        break;
                    }
                }
            }

            if (false === $composerJson || '' === $composerJson) {
                throw new SubmitValidationException('The ZIP archive does not contain a composer.json file.');
            }

            $data = json_decode($composerJson, true);

            if (!\is_array($data)) {
                throw new SubmitValidationException('The composer.json file contains invalid JSON.');
            }

            return $data;
        } finally {
            $zip->close();
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function validate(array $data): void
    {
        if (!isset($data['name']) || !\is_string($data['name']) || '' === trim($data['name'])) {
            throw new SubmitValidationException('composer.json must contain a "name" field.');
        }

        if (!preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $data['name'])) {
            throw new SubmitValidationException('composer.json "name" must be a valid package name (vendor/package).');
        }

        $type = $data['type'] ?? null;
        if (!\in_array($type, self::ALLOWED_TYPES, true)) {
            throw new SubmitValidationException(\sprintf('composer.json "type" must be one of: %s.', implode(', ', self::ALLOWED_TYPES)));
        }

        if (!isset($data['version']) || !\is_string($data['version']) || '' === trim($data['version'])) {
            throw new SubmitValidationException('composer.json must contain a "version" field.');
        }
    }

    /**
     * @param array<string, mixed> $composerData
     */
    public function extractMauticVersion(array $composerData): ?string
    {
        $require = $composerData['require'] ?? [];
        if (\is_array($require)) {
            $mauticConstraint = $require['mautic/core-lib'] ?? $require['mautic/core'] ?? $require['mautic/mautic'] ?? null;
            if (\is_string($mauticConstraint) && '' !== $mauticConstraint) {
                return $mauticConstraint;
            }
        }

        $extra = $composerData['extra']['mautic']['minimum-version'] ?? null;

        return \is_string($extra) && '' !== $extra ? $extra : null;
    }

    public function normalizeVersion(string $version): string
    {
        $version = ltrim($version, 'vV');
        $parts = explode('.', $version);
        while (\count($parts) < 4) {
            $parts[] = '0';
        }

        return implode('.', \array_slice($parts, 0, 4));
    }
}
