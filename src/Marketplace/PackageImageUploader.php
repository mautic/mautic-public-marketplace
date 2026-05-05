<?php

declare(strict_types=1);

namespace App\Marketplace;

use App\Marketplace\Exception\SubmitValidationException;
use App\Supabase\SupabaseClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PackageImageUploader
{
    private const BUCKET = 'package-media';

    private const ALLOWED_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
    ];

    private const MIME_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
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

        return $this->supabaseClient->uploadStorageObject(self::BUCKET, $objectPath, $contents, $mime);
    }

    private function slugify(string $packageName): string
    {
        return preg_replace('/[^a-z0-9_-]/', '_', strtolower($packageName)) ?? 'package';
    }
}
