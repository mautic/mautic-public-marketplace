<?php

declare(strict_types=1);

namespace App\Tests\Marketplace;

use App\Marketplace\PackageImageUploader;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PackageImageUploaderTest extends KernelTestCase
{
    private PackageImageUploader $uploader;

    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->uploader = self::getContainer()->get(PackageImageUploader::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testUploadsBannerAndReturnsBucketRelativePath(): void
    {
        $zipPath = $this->makeZip(['banner.png' => 'png-bytes']);

        $result = $this->uploader->uploadBannerFromZip('testuser/test-campaign', $zipPath);

        self::assertSame('package-media/banners/testuser_test-campaign.png', $result);
    }

    public function testPicksJpegBannerExtension(): void
    {
        $zipPath = $this->makeZip(['banner.jpg' => 'jpg-bytes']);

        $result = $this->uploader->uploadBannerFromZip('vendor/with-jpeg', $zipPath);

        self::assertSame('package-media/banners/vendor_with-jpeg.jpg', $result);
    }

    public function testReturnsNullWhenArchiveHasNoBanner(): void
    {
        $zipPath = $this->makeZip(['composer.json' => '{}', 'entity_data.json' => '[]']);

        self::assertNull($this->uploader->uploadBannerFromZip('testuser/no-banner', $zipPath));
    }

    public function testUploadsGalleryImagesWithAltTexts(): void
    {
        $zipPath = $this->makeZip([
            'gallery/image_1.png' => 'png-bytes',
            'gallery/image_1.alt.txt' => 'First screenshot',
            'gallery/image_2.jpg' => 'jpg-bytes',
        ]);

        $result = $this->uploader->uploadGalleryFromZip('testuser/test-campaign', $zipPath);

        self::assertSame([
            ['url' => 'package-media/testuser/test-campaign/gallery/1.png', 'alt' => 'First screenshot'],
            ['url' => 'package-media/testuser/test-campaign/gallery/2.jpg', 'alt' => ''],
        ], $result);
    }

    public function testReturnsEmptyGalleryWhenArchiveHasNoGalleryImages(): void
    {
        $zipPath = $this->makeZip(['composer.json' => '{}', 'banner.png' => 'png-bytes']);

        self::assertSame([], $this->uploader->uploadGalleryFromZip('testuser/no-gallery', $zipPath));
    }

    /**
     * @param array<string, string> $entries
     */
    private function makeZip(array $entries): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'banner_zip_');
        if (false === $zipPath) {
            throw new \RuntimeException('Failed to create temporary file for test ZIP.');
        }
        $this->tempFiles[] = $zipPath;

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $zipPath;
    }
}
