<?php

declare(strict_types=1);

namespace App\Marketplace;

use App\Auth0\Auth0User;
use App\Marketplace\Dto\SubmitRequest;
use App\Marketplace\Exception\SubmitValidationException;
use App\Stripe\Exception\StripeConnectException;
use App\Stripe\StripeConnectClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PackageSubmitService
{
    public function __construct(
        private readonly MarketplaceApiClient $apiClient,
        private readonly ComposerJsonReader $composerReader,
        private readonly PackageImageUploader $imageUploader,
        private readonly PackageZipUploader $zipUploader,
        private readonly StripeConnectClient $stripe,
        private readonly ValidatorInterface $validator,
        private readonly MetadataSanitizer $sanitizer,
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
     * Mautic-source upload: the ZIP already carries banner/gallery and all metadata in
     * extra.mautic.*, so no form fields or separate image files are needed. Synthesizes
     * a SubmitRequest from composer.json and delegates to the same upsert pipeline.
     *
     * @return array{package_name: string, version: string, status: string, created: bool}
     *
     * @throws SubmitValidationException
     */
    public function submitFromMauticShare(string $zipPath, Auth0User $user): array
    {
        $composerData = $this->composerReader->read($zipPath);
        $this->composerReader->validate($composerData);

        [$vendor] = explode('/', (string) $composerData['name'], 2);
        if ('unknown-vendor' === $vendor) {
            throw new SubmitValidationException('The campaign is missing a vendor name. Please fill in a vendor name in Mautic when sharing the campaign, then try again.');
        }

        $request = $this->requestFromComposer($composerData);
        $this->assertShareLimits($request);
        $zipUrl = $this->zipUploader->upload($request->name, $request->version, $zipPath);

        // Mautic packs the banner and gallery inside the shared ZIP, so extract them here
        // instead of from form fields.
        $bannerUrl = $this->imageUploader->uploadBannerFromZip($request->name, $zipPath);
        $gallery = $this->imageUploader->uploadGalleryFromZip($request->name, $zipPath);

        return $this->upsertPackage($composerData, $request, $user, $zipUrl, $bannerUrl, $gallery);
    }

    /**
     * File-based upload entry (browser-mediated push from Mautic). All metadata lives in
     * the ZIP's composer.json, so this delegates to the same Mautic-share pipeline.
     *
     * @return array{package_name: string, version: string, status: string, created: bool}
     *
     * @throws SubmitValidationException
     */
    public function submitFromFile(string $zipPath, Auth0User $user): array
    {
        return $this->submitFromMauticShare($zipPath, $user);
    }

    /**
     * Share uploads skip the form validation in SubmitApiController, so the
     * size caps from SubmitRequest are enforced here.
     *
     * @throws SubmitValidationException
     */
    private function assertShareLimits(SubmitRequest $request): void
    {
        $violations = $this->validator->validate($request, groups: [SubmitRequest::SHARE_LIMITS_GROUP]);
        if (0 === $violations->count()) {
            return;
        }

        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        throw new SubmitValidationException(implode(' ', array_unique($messages)));
    }

    /**
     * @param array<string, mixed> $composerData
     */
    private function requestFromComposer(array $composerData): SubmitRequest
    {
        $extra = $composerData['extra']['mautic'] ?? [];
        $worksWith = \is_array($extra['works-with'] ?? null) ? array_values(array_filter(array_map('strval', $extra['works-with']))) : [];
        $languages = \is_array($extra['languages'] ?? null) ? array_values(array_filter(array_map('strval', $extra['languages']))) : [];
        $price = $extra['price']['amount'] ?? 0;
        $keywords = \is_array($composerData['keywords'] ?? null) ? array_values(array_filter(array_map('strval', $composerData['keywords']))) : [];

        if ([] === $worksWith) {
            $minVersion = $extra['minimum-version'] ?? null;
            if (\is_string($minVersion) && '' !== $minVersion) {
                $worksWith = [$minVersion];
            }
        }

        return new SubmitRequest(
            name: (string) $composerData['name'],
            version: (string) $composerData['version'],
            category: (string) $composerData['type'],
            headline: (string) ($extra['headline'] ?? ''),
            keywords: $keywords,
            description: (string) ($composerData['description'] ?? ''),
            languages: $languages,
            works_with: $worksWith,
            // License + ownership are UI-form-only concerns; Mautic share-flow uploads carry
            // their declared licence inside composer.json (handled by upsertPackage).
            license_type: 'mautic-share',
            price: (float) $price,
            ip_ownership_accepted: true,
        );
    }

    /**
     * @param array<string, mixed> $composerData
     */
    private function ensureComposerMatchesRequest(array $composerData, SubmitRequest $request): void
    {
        // The package name is intentionally NOT enforced against composer.json:
        // the upload form lets publishers set the marketplace name, so the
        // submitted $request->name takes priority and is used throughout the
        // pipeline (storage paths, upsert identity). Type and version must still
        // match composer.json to keep the published artifact self-consistent.
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
        $packageName = $request->name;
        $campaignUuid = $composerData['extra']['mautic']['campaign-uuid'] ?? null;

        // Dedupe shared campaigns by their stable UUID first so re-shares update the same
        // package even if the user renamed it; fall back to lookup by package name.
        $existingPackage = null;
        if (\is_string($campaignUuid) && '' !== $campaignUuid) {
            $existingPackage = $this->apiClient->getPackageByCampaignUuid($campaignUuid);
        }
        if (null === $existingPackage) {
            $existingPackage = $this->apiClient->getPackageByName($packageName);
        }

        $created = null === $existingPackage;

        // Supply-chain guard: only the original submitter may publish new versions of an
        // existing package. Refuse to update a package owned by someone else (or update
        // is in effect an ownership takeover under the same name).
        if (!$created) {
            $existingOwner = $existingPackage['auth0_user_id'] ?? null;
            if (\is_string($existingOwner) && '' !== $existingOwner && $existingOwner !== $user->getUserIdentifier()) {
                throw new SubmitValidationException('A package with this name already exists and belongs to another user. You can only publish updates to packages you submitted.');
            }
        }

        $status = \is_array($existingPackage) ? ($existingPackage['status'] ?? 'pending') : 'pending';
        if (!\is_string($status) || '' === $status) {
            $status = 'pending';
        }

        $maintainerName = $this->sanitizer->text($user->getName() ?? $user->getEmail() ?? 'Anonymous');
        if ('' === $maintainerName) {
            $maintainerName = 'Anonymous';
        }

        $displayName = $composerData['extra']['mautic']['display-name'] ?? null;
        $displayName = \is_string($displayName) ? $this->sanitizer->text($displayName) : '';
        if ('' === $displayName) {
            $displayName = $this->toDisplayName($packageName);
        }

        // works_with may legitimately be empty in the share flow, but a non-empty
        // submission must not be emptied by sanitization (markup-only entries).
        $worksWith = $this->sanitizer->textList($request->works_with);
        if ([] === $worksWith && [] !== $request->works_with) {
            throw new SubmitValidationException('Supported Mautic versions must contain plain text, not just HTML markup.');
        }

        $packageData = [
            'name' => $packageName,
            'displayname' => $displayName,
            'description' => $request->description,
            'type' => $request->category,
            'time' => (new \DateTimeImmutable())->format('c'),
            'maintainers' => [['name' => $maintainerName]],
            'auth0_user_id' => $user->getUserIdentifier(),
            'headline' => $this->requirePlainText($request->headline, 'Headline'),
            'languages' => $this->sanitizer->textList($request->languages),
            'works_with' => $worksWith,
            'price' => $request->price,
            'pricing_model' => $request->pricing_model,
            'currency' => $request->currency,
            'license_type' => $request->license_type,
            'github_url' => $request->github_url,
            'packagist_url' => $request->packagist_url,
            'documentation' => $request->documentation,
            // The detail page renders the GitHub/Packagist links from repository/url
            // (Packagist-sync columns); mirror the upload's URLs there so they show.
            'repository' => $request->github_url,
            'url' => $request->packagist_url,
            'ip_ownership_accepted' => $request->ip_ownership_accepted,
        ];

        if (\is_string($campaignUuid) && '' !== $campaignUuid) {
            $packageData['campaign_uuid'] = $campaignUuid;
        }

        // Only set when a banner was provided, so re-publishing without one keeps the current image.
        if (null !== $bannerUrl) {
            $packageData['banner_url'] = $bannerUrl;
        }

        if ([] !== $gallery) {
            $packageData['gallery'] = array_map(
                fn (array $image): array => [
                    'url' => $image['url'],
                    'alt' => $this->sanitizer->text($image['alt']),
                ],
                $gallery,
            );
        }

        if ('paid' === $request->pricing_model) {
            $this->attachStripePricing($packageData, $request, $user, $created);
        }

        if ($created) {
            $packageData['downloads'] = ['total' => 0];
            $packageData['status'] = 'pending';
            $this->apiClient->createPackage($packageData);
            $status = 'pending';
        } else {
            // A campaign_uuid match may resolve to a package stored under a
            // different name. Override is enabled, so the submitted name wins:
            // rename the existing row in place (the child FKs cascade the rename
            // via ON UPDATE CASCADE) and carry the new name to the version record.
            $existingName = (string) ($existingPackage['name'] ?? $request->name);
            $packageData['status'] = $status;
            $this->apiClient->updatePackage($existingName, $packageData);
            $packageName = $request->name;
        }

        $smv = $this->composerReader->extractMauticVersion($composerData);

        $sanitizedVersion = $this->requirePlainText($request->version, 'Version');

        $versionData = [
            'package_name' => $packageName,
            'version' => $sanitizedVersion,
            'version_normalized' => $this->composerReader->normalizeVersion($sanitizedVersion),
            'description' => $request->description,
            'keywords' => $this->sanitizer->textList($request->keywords),
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
            'package_name' => $packageName,
            'version' => $sanitizedVersion,
            'status' => $status,
            'created' => $created,
        ];
    }

    /**
     * Requires the vendor to be onboarded onto Stripe and, on first publish, creates the
     * Stripe product/price for the paid package. Stores the Stripe references and the
     * vendor's connected-account id on the package so checkout can route the split.
     *
     * @param array<string, mixed> $packageData
     *
     * @throws SubmitValidationException
     */
    private function attachStripePricing(array &$packageData, SubmitRequest $request, Auth0User $user, bool $created): void
    {
        $account = $this->apiClient->getStripeConnectAccount($user->getUserIdentifier());
        if (null === $account || !$account['details_submitted']) {
            throw new SubmitValidationException('Connect your Stripe account before publishing a paid package.');
        }

        $packageData['vendor_stripe_account_id'] = $account['stripe_account_id'];

        // Create the Stripe product/price once, on first publish. Re-publishing keeps the
        // existing references (they are simply not re-sent, so the update leaves them intact).
        if (!$created || !$this->stripe->isConfigured()) {
            return;
        }

        $displayName = \is_string($packageData['displayname'] ?? null) && '' !== $packageData['displayname']
            ? (string) $packageData['displayname']
            : $request->name;

        try {
            $references = $this->stripe->createProductWithPrice(
                $displayName,
                $request->description,
                (int) round($request->price * 100),
                (string) $request->currency,
            );
        } catch (StripeConnectException $exception) {
            throw new SubmitValidationException($exception->getMessage());
        }

        $packageData['stripe_product_id'] = $references['product_id'];
        $packageData['stripe_price_id'] = $references['price_id'];
    }

    /**
     * Sanitize a field whose NotBlank guarantee must survive sanitization:
     * input that was non-blank but consisted only of markup is rejected
     * instead of being silently stored as an empty string. Fields that are
     * allowed to be empty (share flow) pass through untouched.
     *
     * @throws SubmitValidationException
     */
    private function requirePlainText(string $value, string $field): string
    {
        $clean = $this->sanitizer->text($value);
        if ('' === $clean && '' !== trim($value)) {
            throw new SubmitValidationException(\sprintf('%s must contain plain text, not just HTML markup.', $field));
        }

        return $clean;
    }

    private function toDisplayName(string $packageName): string
    {
        $parts = explode('/', $packageName);
        $name = end($parts);

        return ucwords(str_replace(['-', '_'], ' ', $name));
    }
}
