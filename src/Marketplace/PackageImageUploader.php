<?php

declare(strict_types=1);

namespace App\Marketplace;

use App\Marketplace\Exception\SubmitValidationException;
use App\Supabase\Exception\SupabaseApiException;
use App\Supabase\SupabaseClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PackageImageUploader
{
    private const BUCKET = 'package-media';

    private const ALLOWED_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/pjpeg',
        'image/webp',
        'image/gif',
    ];

    // All JPEG variants normalize to ".jpg" so storage paths stay uniform regardless
    // of whether the uploaded file was named .jpg, .jpeg, or came in as image/pjpeg.
    private const MIME_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    // Mautic packs the banner at the ZIP root as "banner.<ext>" (see CampaignShareService::addImageToZip).
    private const ALLOWED_EXTENSIONS = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly SupabaseClient $supabaseClient,
    ) {
    }

    public function uploadBanner(string $packageName, UploadedFile $file): string
    {
        return $this->upload($packageName, $file, 'banner');
    }

    public function uploadGalleryImage(string $packageName, UploadedFile $file, int $index): string
    {
        return $this->upload($packageName, $file, 'gallery/'.$index);
    }

    /**
     * Extracts the banner image packed in a Mautic-shared ZIP, stores it in marketplace-owned storage,
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
     * Extracts the gallery images packed in a Mautic-shared ZIP (gallery/image_N.<ext> with an
     * optional gallery/image_N.alt.txt alongside, see CampaignShareService::share), stores them
     * in marketplace-owned storage, and returns entries shaped for packages.gallery. Returns an
     * empty list when the archive carries no gallery images.
     *
     * @return list<array{url: string, alt: string}>
     *
     * @throws SupabaseApiException
     */
    public function uploadGalleryFromZip(string $packageName, string $zipPath): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath)) {
            return [];
        }

        $gallery = [];

        try {
            // Mautic's share form allows up to 8 gallery images, numbered from 1.
            for ($i = 1; $i <= 8; ++$i) {
                foreach (self::ALLOWED_EXTENSIONS as $extension => $mime) {
                    $contents = $zip->getFromName('gallery/image_'.$i.'.'.$extension);
                    if (false === $contents || '' === $contents) {
                        continue;
                    }

                    if (\strlen($contents) > self::MAX_BYTES) {
                        // An oversized gallery image shouldn't block the whole publish; skip it.
                        continue 2;
                    }

                    $objectPath = $this->slugify($packageName).'/gallery/'.$i.'.'.$extension;
                    $this->supabaseClient->uploadStorageObject(self::BUCKET, $objectPath, $contents, $mime);

                    $alt = $zip->getFromName('gallery/image_'.$i.'.alt.txt');
                    $gallery[] = [
                        'url' => self::BUCKET.'/'.$objectPath,
                        'alt' => \is_string($alt) ? trim($alt) : '',
                    ];

                    continue 2;
                }
            }

            return $gallery;
        } finally {
            $zip->close();
        }
    }

    private function upload(string $packageName, UploadedFile $file, string $prefix): string
    {
        $mime = $file->getMimeType() ?? '';
        if (!\in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new SubmitValidationException(\sprintf('Image type "%s" is not allowed. Use PNG, JPEG, WebP or GIF.', $mime));
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new SubmitValidationException(\sprintf('Image is too large. Maximum size is %dMB.', self::MAX_BYTES / 1024 / 1024));
        }

        $contents = file_get_contents($file->getPathname());
        if (false === $contents) {
            throw new SubmitValidationException('Failed to read uploaded image.');
        }

        $extension = self::MIME_EXTENSIONS[$mime];
        $objectPath = $this->slugify($packageName).'/'.$prefix.'.'.$extension;

        $this->supabaseClient->uploadStorageObject(self::BUCKET, $objectPath, $contents, $mime);

        // Persist the bucket-relative path (not the server-side full URL), matching
        // uploadBannerFromZip(); the browser-facing URL is built at render time.
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

    private function slugify(string $packageName): string
    {
        // Preserve the vendor/package "/" so distinct names can't collide into the same
        // storage path (e.g. "a_b/c" vs "a/b_c" would both flatten to "a_b_c").
        $slug = preg_replace('#[^a-z0-9/_-]#', '_', strtolower($packageName)) ?? 'package';
        $slug = trim($slug, '/');

        return '' !== $slug ? $slug : 'package';
    }

    // Flattens vendor/package to a single segment for the banner filename packed from a Mautic share.
    private function slugifyName(string $packageName): string
    {
        return preg_replace('/[^a-z0-9_-]/', '_', strtolower($packageName)) ?? 'package';
    }
}
