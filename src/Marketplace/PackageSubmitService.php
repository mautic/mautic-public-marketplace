<?php

declare(strict_types=1);

namespace App\Marketplace;

use App\Auth0\Auth0User;
use App\Marketplace\Dto\SubmitRequest;
use App\Marketplace\Exception\SubmitValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PackageSubmitService
{
    public function __construct(
        private readonly MarketplaceApiClient $apiClient,
        private readonly ComposerJsonReader $composerReader,
        private readonly PackageImageUploader $imageUploader,
        private readonly PackageZipUploader $zipUploader,
    ) {
    }

    /**
     * @param list<UploadedFile> $galleryFiles
     *
     * @return array{package_name: string, version: string, status: string, created: bool}
     *
     * @throws SubmitValidationException
     */
    public function submit(
        string $zipPath,
        SubmitRequest $request,
        Auth0User $user,
        ?UploadedFile $bannerFile = null,
        array $galleryFiles = [],
    ): array {
        $composerData = $this->composerReader->read($zipPath);
        $this->composerReader->validate($composerData);
        $this->ensureComposerMatchesRequest($composerData, $request);

        $zipUrl = $this->zipUploader->upload($request->name, $request->version, $zipPath);

        $bannerUrl = null;
        if ($bannerFile instanceof UploadedFile) {
            $bannerUrl = $this->imageUploader->uploadBanner($request->name, $bannerFile);
        }

        $gallery = [];
        foreach ($galleryFiles as $index => $file) {
            $url = $this->imageUploader->uploadGalleryImage($request->name, $file, $index);
            $gallery[] = [
                'url' => $url,
                'alt' => $request->gallery_alt[$index] ?? '',
            ];
        }

        return $this->upsertPackage($composerData, $request, $user, $zipUrl, $bannerUrl, $gallery);
    }

    /**
     * @param array<string, mixed> $composerData
     */
    private function ensureComposerMatchesRequest(array $composerData, SubmitRequest $request): void
    {
        if (($composerData['name'] ?? null) !== $request->name) {
            throw new SubmitValidationException('Submitted package name does not match composer.json.');
        }

        if (($composerData['type'] ?? null) !== $request->category) {
            throw new SubmitValidationException('Submitted category does not match composer.json type.');
        }

        if (($composerData['version'] ?? null) !== $request->version) {
            throw new SubmitValidationException('Submitted version does not match composer.json.');
        }
    }

    /**
     * @param array<string, mixed>                  $composerData
     * @param list<array{url: string, alt: string}> $gallery
     *
     * @return array{package_name: string, version: string, status: string, created: bool}
     */
    private function upsertPackage(
        array $composerData,
        SubmitRequest $request,
        Auth0User $user,
        string $zipUrl,
        ?string $bannerUrl,
        array $gallery,
    ): array {
        $existingPackage = $this->apiClient->getPackageByName($request->name);
        $created = null === $existingPackage;
        $status = \is_array($existingPackage) ? ($existingPackage['status'] ?? 'pending') : 'pending';
        if (!\is_string($status) || '' === $status) {
            $status = 'pending';
        }

        $maintainerName = $user->getName() ?? $user->getEmail() ?? 'Anonymous';

        $packageData = [
            'name' => $request->name,
            'displayname' => $composerData['extra']['mautic']['display-name'] ?? $this->toDisplayName($request->name),
            'description' => $composerData['description'] ?? null,
            'type' => $request->category,
            'time' => (new \DateTimeImmutable())->format('c'),
            'maintainers' => [['name' => $maintainerName]],
            'auth0_user_id' => $user->getUserIdentifier(),
            'headline' => $request->headline,
            'languages' => $request->languages,
            'works_with' => $request->works_with,
            'price' => $request->price,
            'license_type' => $request->license_type,
            'github_url' => $request->github_url,
            'packagist_url' => $request->packagist_url,
            'documentation' => $request->documentation,
            'ip_ownership_accepted' => $request->ip_ownership_accepted,
        ];

        if (null !== $bannerUrl) {
            $packageData['banner_url'] = $bannerUrl;
        }

        if ([] !== $gallery) {
            $packageData['gallery'] = $gallery;
        }

        if ($created) {
            $packageData['downloads'] = ['total' => 0];
            $packageData['status'] = 'pending';
            $this->apiClient->createPackage($packageData);
            $status = 'pending';
        } else {
            $packageData['status'] = $status;
            unset($packageData['name']);
            $this->apiClient->updatePackage($request->name, $packageData);
        }

        $smv = $this->composerReader->extractMauticVersion($composerData);

        $versionData = [
            'package_name' => $request->name,
            'version' => $request->version,
            'version_normalized' => $this->composerReader->normalizeVersion($request->version),
            'description' => $composerData['description'] ?? null,
            'keywords' => $request->keywords,
            'license' => $composerData['license'] ?? [$request->license_type],
            'authors' => $composerData['authors'] ?? null,
            'type' => $request->category,
            'dist' => ['type' => 'zip', 'url' => $zipUrl],
            'time' => (new \DateTimeImmutable())->format('c'),
            'smv' => $smv,
            'require' => $composerData['require'] ?? null,
            'extra' => $composerData['extra'] ?? null,
        ];

        $this->apiClient->upsertVersion($versionData);

        return [
            'package_name' => $request->name,
            'version' => $request->version,
            'status' => $status,
            'created' => $created,
        ];
    }

    private function toDisplayName(string $packageName): string
    {
        $parts = explode('/', $packageName);
        $name = end($parts);

        return ucwords(str_replace(['-', '_'], ' ', $name));
    }
}
