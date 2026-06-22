<?php

declare(strict_types=1);

namespace App\Marketplace;

use App\Marketplace\Exception\SubmitValidationException;
use App\Supabase\SupabaseClient;

final class PackageZipUploader
{
    private const BUCKET = 'package-media';

    // Supabase enforces a global upload size limit (50MB by default). Reject larger
    // archives here so the user gets a clear message instead of an opaque storage error.
    private const MAX_BYTES = 50 * 1024 * 1024;

    public function __construct(
        private readonly SupabaseClient $supabaseClient,
    ) {
    }

    /**
     * Stores the package archive in marketplace-owned storage and returns its public URL.
     * This makes the package installable regardless of where the ZIP was originally hosted.
     *
     * @throws SubmitValidationException
     */
    public function upload(string $packageName, string $version, string $zipPath): string
    {
        $contents = file_get_contents($zipPath);
        if (false === $contents) {
            throw new SubmitValidationException('Failed to read the uploaded ZIP file.');
        }

        if (\strlen($contents) > self::MAX_BYTES) {
            throw new SubmitValidationException(\sprintf('The ZIP file is too large. Maximum size is %dMB.', self::MAX_BYTES / 1024 / 1024));
        }

        $objectPath = 'dist/'.$this->slugifyName($packageName).'/'.$this->slugifyVersion($version).'.zip';

        return $this->supabaseClient->uploadStorageObject(self::BUCKET, $objectPath, $contents, 'application/zip');
    }

    // Mirrors PackageImageUploader::slugifyName so a package's archive and images share the same folder name.
    private function slugifyName(string $packageName): string
    {
        return preg_replace('/[^a-z0-9_-]/', '_', strtolower($packageName)) ?? 'package';
    }

    // Keeps dots so semantic versions like "1.2.0" stay readable in the storage path.
    private function slugifyVersion(string $version): string
    {
        return preg_replace('/[^a-z0-9_.-]/', '_', strtolower($version)) ?? 'version';
    }
}
