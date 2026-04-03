<?php

declare(strict_types=1);

namespace App\Marketplace;

use App\Marketplace\Exception\SubmitValidationException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PackageSubmitService
{
    public function __construct(
        private readonly MarketplaceApiClient $apiClient,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Downloads the zip from assetUrl, reads composer.json, and creates/updates the package.
     *
     * @param array<string, mixed> $userInfo Auth0 user info
     *
     * @return array{package_name: string, version: string, created: bool}
     *
     * @throws SubmitValidationException
     */
    public function submit(string $assetUrl, array $userInfo): array
    {
        $zipPath = $this->downloadZip($assetUrl);

        try {
            $composerData = $this->readComposerJson($zipPath);
            $this->validateComposerData($composerData);

            return $this->upsertPackage($composerData, $assetUrl, $userInfo);
        } finally {
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }
        }
    }

    private function downloadZip(string $assetUrl): string
    {
        $response = $this->httpClient->request('GET', $assetUrl);

        if (200 !== $response->getStatusCode()) {
            throw new SubmitValidationException(\sprintf('Failed to download asset from URL (HTTP %d).', $response->getStatusCode()));
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'mautic_submit_');
        if (false === $tmpFile) {
            throw new SubmitValidationException('Failed to create temporary file.');
        }

        file_put_contents($tmpFile, $response->getContent());

        return $tmpFile;
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposerJson(string $zipPath): array
    {
        $zip = new \ZipArchive();
        $result = $zip->open($zipPath);

        if (true !== $result) {
            throw new SubmitValidationException('The uploaded file is not a valid ZIP archive.');
        }

        try {
            $composerJson = $zip->getFromName('composer.json');

            if (false === $composerJson) {
                // Try to find composer.json in a subdirectory
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
    private function validateComposerData(array $data): void
    {
        if (!isset($data['name']) || !\is_string($data['name']) || '' === trim($data['name'])) {
            throw new SubmitValidationException('composer.json must contain a "name" field.');
        }

        if (!preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $data['name'])) {
            throw new SubmitValidationException('composer.json "name" must be a valid package name (vendor/package).');
        }

        $type = $data['type'] ?? null;
        if ('mautic-resource' !== $type) {
            throw new SubmitValidationException('composer.json "type" must be "mautic-resource".');
        }

        if (!isset($data['version']) || !\is_string($data['version']) || '' === trim($data['version'])) {
            throw new SubmitValidationException('composer.json must contain a "version" field.');
        }
    }

    /**
     * @param array<string, mixed> $composerData
     * @param array<string, mixed> $userInfo
     *
     * @return array{package_name: string, version: string, created: bool}
     */
    private function upsertPackage(array $composerData, string $assetUrl, array $userInfo): array
    {
        $packageName = (string) $composerData['name'];
        $version = (string) $composerData['version'];
        $campaignUuid = $composerData['extra']['mautic']['campaign-uuid'] ?? null;

        $existingPackage = $this->apiClient->getPackageByName($packageName);
        $created = null === $existingPackage;

        $maintainerName = $userInfo['name'] ?? $userInfo['email'] ?? 'Anonymous';

        $packageData = [
            'name' => $packageName,
            'displayname' => $composerData['extra']['mautic']['display-name'] ?? $this->toDisplayName($packageName),
            'description' => $composerData['description'] ?? null,
            'type' => 'mautic-resource',
            'time' => (new \DateTimeImmutable())->format('c'),
            'maintainers' => json_encode([['name' => $maintainerName]]),
        ];

        if (\is_string($campaignUuid) && '' !== $campaignUuid) {
            $packageData['campaign_uuid'] = $campaignUuid;
        }

        if ($created) {
            $packageData['downloads'] = json_encode(['total' => 0]);
            $this->apiClient->createPackage($packageData);
        } else {
            unset($packageData['name'], $packageData['downloads']);
            $this->apiClient->updatePackage($packageName, $packageData);
        }

        $smv = $this->extractMauticVersion($composerData);

        $versionData = [
            'package_name' => $packageName,
            'version' => $version,
            'version_normalized' => $this->normalizeVersion($version),
            'description' => $composerData['description'] ?? null,
            'keywords' => isset($composerData['keywords']) ? json_encode($composerData['keywords']) : null,
            'license' => isset($composerData['license']) ? json_encode($composerData['license']) : null,
            'authors' => isset($composerData['authors']) ? json_encode($composerData['authors']) : null,
            'type' => 'mautic-resource',
            'dist' => json_encode(['url' => $assetUrl, 'type' => 'zip']),
            'time' => (new \DateTimeImmutable())->format('c'),
            'smv' => $smv,
        ];

        $this->apiClient->upsertVersion($versionData);

        return [
            'package_name' => $packageName,
            'version' => $version,
            'created' => $created,
        ];
    }

    private function toDisplayName(string $packageName): string
    {
        $parts = explode('/', $packageName);
        $name = end($parts);

        return ucwords(str_replace(['-', '_'], ' ', $name));
    }

    /**
     * @param array<string, mixed> $composerData
     */
    private function extractMauticVersion(array $composerData): ?string
    {
        $require = $composerData['require'] ?? [];
        if (!\is_array($require)) {
            return null;
        }

        $mauticConstraint = $require['mautic/core'] ?? $require['mautic/mautic'] ?? null;
        if (!\is_string($mauticConstraint)) {
            return $composerData['extra']['mautic']['minimum-version'] ?? null;
        }

        // Extract the base version number from constraint (e.g. "^5.0" -> "5.0")
        if (preg_match('/(\d+\.\d+)/', $mauticConstraint, $matches)) {
            return $matches[1];
        }

        return $mauticConstraint;
    }

    private function normalizeVersion(string $version): string
    {
        $version = ltrim($version, 'vV');

        $parts = explode('.', $version);
        while (\count($parts) < 4) {
            $parts[] = '0';
        }

        return implode('.', \array_slice($parts, 0, 4));
    }
}
