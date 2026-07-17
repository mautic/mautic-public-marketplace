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

    // Mautic packs the banner as "assets/banner.<ext>" (see CampaignShareService::addImageToZip);
    // older share archives used the ZIP root, so both locations are read.
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
     * Extracts the gallery images packed in a Mautic-shared ZIP ("assets/gallery/image_N.<ext>"
     * with an optional "image_N.alt.txt" alongside, or the ZIP root for older archives — see
     * CampaignShareService::buildShareZip), stores them in marketplace-owned storage, and
     * returns entries shaped for packages.gallery. Returns an empty list when the archive
     * carries no gallery images.
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

        $images = [];
        try {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $entry = (string) $zip->getNameIndex($i);
                if (1 !== preg_match('#^(?:assets/)?gallery/image_(\d+)\.([a-z]+)$#', $entry, $matches)) {
                    continue;
                }

                if (!isset(self::ALLOWED_EXTENSIONS[$matches[2]])) {
                    continue;
                }

                $stat = $zip->statIndex($i);
                if (false === $stat || $stat['size'] > self::MAX_BYTES) {
                    // Reject on the declared uncompressed size before decompressing, so a
                    // crafted entry (zip bomb) can't exhaust memory; skip the oversized image.
                    continue;
                }

                $contents = $zip->getFromName($entry);
                if (false === $contents || '' === $contents) {
                    continue;
                }

                $alt = $zip->getFromName(substr($entry, 0, -\strlen('.'.$matches[2])).'.alt.txt');
                $images[(int) $matches[1]] = [
                    'extension' => $matches[2],
                    'contents' => $contents,
                    'mime' => self::ALLOWED_EXTENSIONS[$matches[2]],
                    'alt' => \is_string($alt) ? trim($alt) : '',
                ];
            }
        } finally {
            $zip->close();
        }

        ksort($images);

        $gallery = [];
        foreach ($images as $slot => $image) {
            $objectPath = $this->slugify($packageName).'/gallery/'.$slot.'.'.$image['extension'];
            $this->supabaseClient->uploadStorageObject(self::BUCKET, $objectPath, $image['contents'], $image['mime']);

            $gallery[] = [
                'url' => self::BUCKET.'/'.$objectPath,
                'alt' => $image['alt'],
            ];
        }

        return $gallery;
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
            foreach (['assets/banner.', 'banner.'] as $prefix) {
                foreach (array_keys(self::ALLOWED_EXTENSIONS) as $extension) {
                    $stat = $zip->statName($prefix.$extension);
                    if (false === $stat) {
                        continue;
                    }

                    if ($stat['size'] > self::MAX_BYTES) {
                        // Reject on the declared uncompressed size before decompressing, so a
                        // crafted entry (zip bomb) can't exhaust memory; treat it as no banner.
                        continue;
                    }

                    $contents = $zip->getFromName($prefix.$extension);
                    if (false !== $contents && '' !== $contents) {
                        return [$extension, $contents];
                    }
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
