<?php

declare(strict_types=1);

namespace App\Marketplace;

use App\Auth0\Auth0User;
use App\Marketplace\Exception\SubmitValidationException;
use App\Supabase\Exception\SupabaseApiException;

final class PackageSubmitService
{
    public function __construct(
        private readonly MarketplaceApiClient $apiClient,
        private readonly PackageZipUploader $zipUploader,
    ) {
    }

    /**
     * Reads composer.json from an already-saved ZIP and creates/updates the package.
     * Caller manages the file lifetime (typically a Symfony UploadedFile, auto-cleaned by PHP at request shutdown).
     *
     * @return array{package_name: string, version: string, created: bool}
     *
     * @throws SubmitValidationException
     * @throws SupabaseApiException
     */
    public function submitFromFile(string $zipPath, Auth0User $user): array
    {
        $composerData = $this->readComposerJson($zipPath);
        $this->validateComposerData($composerData);

        // Copy the archive into marketplace-owned storage so installs don't depend on
        // the originating Mautic instance staying reachable.
        $distUrl = $this->zipUploader->upload(
            (string) $composerData['name'],
            (string) $composerData['version'],
            $zipPath,
        );

        return $this->upsertPackage($composerData, $distUrl, $user);
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
                // Try one level deep only (e.g. "folder/composer.json"), not deeper paths like "vendor/x/composer.json"
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

        [$vendor] = explode('/', $data['name'], 2);
        if ('unknown-vendor' === $vendor) {
            throw new SubmitValidationException('The campaign is missing a vendor name. Please fill in a vendor name in Mautic when sharing the campaign, then try again.');
        }

        $type = $data['type'] ?? null;
        $allowedTypes = ['mautic-plugin', 'mautic-theme', 'mautic-resource'];
        if (!\in_array($type, $allowedTypes, true)) {
            throw new SubmitValidationException(\sprintf('composer.json "type" must be one of: %s.', implode(', ', $allowedTypes)));
        }

        if (!isset($data['version']) || !\is_string($data['version']) || '' === trim($data['version'])) {
            throw new SubmitValidationException('composer.json must contain a "version" field.');
        }
    }

    /**
     * @param array<string, mixed> $composerData
     *
     * @return array{package_name: string, version: string, created: bool}
     */
    private function upsertPackage(array $composerData, string $distUrl, Auth0User $user): array
    {
        $packageName = (string) $composerData['name'];
        $version = (string) $composerData['version'];
        $type = (string) $composerData['type'];
        $campaignUuid = $composerData['extra']['mautic']['campaign-uuid'] ?? null;

        $existingPackage = null;
        if (\is_string($campaignUuid) && '' !== $campaignUuid) {
            $existingPackage = $this->apiClient->getPackageByCampaignUuid($campaignUuid);
        }
        if (null === $existingPackage) {
            $existingPackage = $this->apiClient->getPackageByName($packageName);
        }
        $created = null === $existingPackage;

        $maintainerName = $user->getName() ?? $user->getEmail() ?? 'Anonymous';

        $packageData = [
            'name' => $packageName,
            'displayname' => $composerData['extra']['mautic']['display-name'] ?? $this->toDisplayName($packageName),
            'description' => $composerData['description'] ?? null,
            'type' => $type,
            'time' => (new \DateTimeImmutable())->format('c'),
            'maintainers' => [['name' => $maintainerName]],
            'auth0_user_id' => $user->getUserIdentifier(),
        ];

        if (\is_string($campaignUuid) && '' !== $campaignUuid) {
            $packageData['campaign_uuid'] = $campaignUuid;
        }

        if ($created) {
            $packageData['downloads'] = ['total' => 0];
            $this->apiClient->createPackage($packageData);
        } else {
            $existingName = (string) ($existingPackage['name'] ?? $packageName);
            unset($packageData['name']);
            $this->apiClient->updatePackage($existingName, $packageData);
            $packageName = $existingName;
        }

        $smv = $this->extractMauticVersion($composerData);

        $versionData = [
            'package_name' => $packageName,
            'version' => $version,
            'version_normalized' => $this->normalizeVersion($version),
            'description' => $composerData['description'] ?? null,
            'keywords' => $composerData['keywords'] ?? null,
            'license' => $composerData['license'] ?? null,
            'authors' => $composerData['authors'] ?? null,
            'type' => $type,
            'dist' => ['url' => $distUrl, 'type' => 'zip'],
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

        $mauticConstraint = $require['mautic/core-lib'] ?? $require['mautic/core'] ?? $require['mautic/mautic'] ?? null;
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
