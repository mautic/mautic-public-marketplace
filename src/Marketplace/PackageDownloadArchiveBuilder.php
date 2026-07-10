<?php

declare(strict_types=1);

namespace App\Marketplace;

use App\Marketplace\Dto\PackageDetail;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Rebuilds a package's dist archive with the marketplace-hosted presentation
 * images (banner + gallery) added, so "Download this package" delivers
 * everything the publisher attached — not just the files inside the uploaded
 * ZIP. Wizard uploads store those images as separate storage objects that are
 * only referenced from the package row, hence the repack at download time.
 */
final class PackageDownloadArchiveBuilder
{
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Returns the path of a temporary ZIP ready to stream, or null when there
     * is nothing to add (no images, images already packed by the Mautic share
     * flow, or the archive could not be fetched) — callers then fall back to
     * redirecting to the stored archive.
     */
    public function build(PackageDetail $detail, string $archiveUrl): ?string
    {
        if (null === $detail->bannerURL && null === $detail->gallery) {
            return null;
        }

        $tmpPath = $this->fetchArchive($detail->name, $archiveUrl);
        if (null === $tmpPath) {
            return null;
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($tmpPath)) {
            @unlink($tmpPath);

            return null;
        }

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entries[] = (string) $zip->getNameIndex($i);
        }

        $added = false;

        // Mautic share-flow archives already carry banner/gallery entries; keep
        // the publisher's originals and only fill in what is missing.
        if (null !== $detail->bannerURL && [] === preg_grep('#^banner\.#', $entries)) {
            $added = $this->addImage($zip, $detail->bannerURL, 'banner', $detail->name);
        }

        if (null !== $detail->gallery && [] === preg_grep('#^gallery/#', $entries)) {
            foreach ($detail->gallery as $index => $image) {
                $baseName = 'gallery/image_'.($index + 1);
                if (!$this->addImage($zip, $image['src'], $baseName, $detail->name)) {
                    continue;
                }

                $added = true;
                if ('' !== $image['alt']) {
                    $zip->addFromString($baseName.'.alt.txt', $image['alt']);
                }
            }
        }

        $zip->close();

        if (!$added) {
            @unlink($tmpPath);

            return null;
        }

        return $tmpPath;
    }

    private function fetchArchive(string $packageName, string $archiveUrl): ?string
    {
        try {
            $contents = $this->httpClient->request('GET', $archiveUrl)->getContent();
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not fetch the stored package archive to bundle its images.', [
                'exception' => $exception,
                'package' => $packageName,
                'archive_url' => $archiveUrl,
            ]);

            return null;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'pkg-download-');
        if (false === $tmpPath || false === file_put_contents($tmpPath, $contents)) {
            return null;
        }

        return $tmpPath;
    }

    private function addImage(\ZipArchive $zip, string $imageUrl, string $baseName, string $packageName): bool
    {
        try {
            $contents = $this->httpClient->request('GET', $imageUrl)->getContent();
        } catch (\Throwable $exception) {
            // A missing image must never block the package download itself.
            $this->logger->warning('Could not fetch a package image to bundle into the download archive.', [
                'exception' => $exception,
                'package' => $packageName,
                'image_url' => $imageUrl,
            ]);

            return false;
        }

        return $zip->addFromString($baseName.'.'.$this->extensionFromUrl($imageUrl), $contents);
    }

    private function extensionFromUrl(string $url): string
    {
        $path = (string) (parse_url($url, \PHP_URL_PATH) ?: '');
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return \in_array($extension, self::IMAGE_EXTENSIONS, true) ? $extension : 'png';
    }
}
