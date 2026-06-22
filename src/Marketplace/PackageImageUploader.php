<?php

declare(strict_types=1);

namespace App\Marketplace;

use App\Supabase\Exception\SupabaseApiException;
use App\Supabase\SupabaseClient;

final class PackageImageUploader
{
    private const BUCKET = 'package-media';

    // Banner images are small previews; reject anything unreasonably large before sending it to storage.
    private const MAX_BYTES = 10 * 1024 * 1024;

    // Mautic packs the banner at the ZIP root as "banner.<ext>" (see CampaignShareService::addImageToZip).
    private const ALLOWED_EXTENSIONS = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    public function __construct(
        private readonly SupabaseClient $supabaseClient,
    ) {
    }

    /**
     * Extracts the banner image packed in the published ZIP, stores it in marketplace-owned storage,
     * and returns the bucket-relative path to persist in packages.banner_url. Returns null when the
     * archive carries no banner, so callers leave an existing banner untouched.
     *
     * @throws SupabaseApiException
     */
    public function uploadBannerFromZip(string $packageName, string $zipPath): ?string
    {
        $banner = $this->readBanner($zipPath);
        if (null === $banner) {
            return null;
        }

        [$extension, $contents] = $banner;

        if (\strlen($contents) > self::MAX_BYTES) {
            // A banner exceeding the limit shouldn't block the whole publish; skip it instead.
            return null;
        }

        $objectPath = 'banners/'.$this->slugifyName($packageName).'.'.$extension;
        $this->supabaseClient->uploadStorageObject(self::BUCKET, $objectPath, $contents, self::ALLOWED_EXTENSIONS[$extension]);

        // banner_url stores the bucket-relative path; MarketplaceApiClient::toBannerUrl turns it into a public URL.
        return self::BUCKET.'/'.$objectPath;
    }

    /**
     * @return array{0: string, 1: string}|null Extension and raw bytes, or null when no banner is present
     */
    private function readBanner(string $zipPath): ?array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath)) {
            return null;
        }

        try {
            foreach (array_keys(self::ALLOWED_EXTENSIONS) as $extension) {
                $contents = $zip->getFromName('banner.'.$extension);
                if (false !== $contents && '' !== $contents) {
                    return [$extension, $contents];
                }
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    // Mirrors PackageZipUploader::slugifyName so a package's archive and images share the same folder name.
    private function slugifyName(string $packageName): string
    {
        return preg_replace('/[^a-z0-9_-]/', '_', strtolower($packageName)) ?? 'package';
    }
}
