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
        $zipPath = $this->makeZip(['banner.png' => $this->imageBytes('png')]);

        $result = $this->uploader->uploadBannerFromZip('testuser/test-campaign', $zipPath);

        self::assertSame('package-media/banners/testuser_test-campaign.png', $result);
    }

    public function testPicksJpegBannerExtension(): void
    {
        $zipPath = $this->makeZip(['banner.jpg' => $this->imageBytes('jpg')]);

        $result = $this->uploader->uploadBannerFromZip('vendor/with-jpeg', $zipPath);

        self::assertSame('package-media/banners/vendor_with-jpeg.jpg', $result);
    }

    public function testReturnsNullWhenArchiveHasNoBanner(): void
    {
        $zipPath = $this->makeZip(['composer.json' => '{}', 'entity_data.json' => '[]']);

        self::assertNull($this->uploader->uploadBannerFromZip('testuser/no-banner', $zipPath));
    }

    public function testReadsBannerFromAssetsDirectory(): void
    {
        $zipPath = $this->makeZip(['assets/banner.png' => $this->imageBytes('png')]);

        $result = $this->uploader->uploadBannerFromZip('testuser/assets-banner', $zipPath);

        self::assertSame('package-media/banners/testuser_assets-banner.png', $result);
    }

    public function testUploadsGalleryImagesWithAltTextsFromAssetsDirectory(): void
    {
        $zipPath = $this->makeZip([
            'assets/gallery/image_1.png' => $this->imageBytes('png'),
            'assets/gallery/image_1.alt.txt' => 'First screenshot',
            'assets/gallery/image_2.jpg' => $this->imageBytes('jpg'),
        ]);

        $gallery = $this->uploader->uploadGalleryFromZip('testuser/with-gallery', $zipPath);

        self::assertSame([
            ['url' => 'package-media/testuser/with-gallery/gallery/1.png', 'alt' => 'First screenshot'],
            ['url' => 'package-media/testuser/with-gallery/gallery/2.jpg', 'alt' => ''],
        ], $gallery);
    }

    public function testReadsGalleryFromLegacyZipRoot(): void
    {
        $zipPath = $this->makeZip([
            'gallery/image_1.png' => $this->imageBytes('png'),
            'gallery/image_1.alt.txt' => 'Legacy layout',
            'gallery/image_2.jpg' => $this->imageBytes('jpg'),
        ]);

        $gallery = $this->uploader->uploadGalleryFromZip('testuser/legacy-gallery', $zipPath);

        self::assertSame([
            ['url' => 'package-media/testuser/legacy-gallery/gallery/1.png', 'alt' => 'Legacy layout'],
            ['url' => 'package-media/testuser/legacy-gallery/gallery/2.jpg', 'alt' => ''],
        ], $gallery);
    }

    public function testReturnsEmptyGalleryWhenArchiveHasNoGalleryImages(): void
    {
        $zipPath = $this->makeZip(['composer.json' => '{}', 'banner.png' => $this->imageBytes('png')]);

        self::assertSame([], $this->uploader->uploadGalleryFromZip('testuser/no-gallery', $zipPath));
    }

    public function testSkipsBannerWithBombDimensions(): void
    {
        $zipPath = $this->makeZip(['banner.png' => $this->oversizedImageHeader()]);

        self::assertNull($this->uploader->uploadBannerFromZip('testuser/bomb-banner', $zipPath));
    }

    public function testSkipsGalleryImageWithBombDimensionsButKeepsTheRest(): void
    {
        $zipPath = $this->makeZip([
            'assets/gallery/image_1.png' => $this->oversizedImageHeader(),
            'assets/gallery/image_2.png' => $this->imageBytes('png'),
        ]);

        $gallery = $this->uploader->uploadGalleryFromZip('testuser/mixed-gallery', $zipPath);

        self::assertSame([
            ['url' => 'package-media/testuser/mixed-gallery/gallery/2.png', 'alt' => ''],
        ], $gallery);
    }

    public function testSkipsEntriesThatAreNotImagesAtAll(): void
    {
        $zipPath = $this->makeZip(['banner.png' => '<?php echo "not an image";']);

        self::assertNull($this->uploader->uploadBannerFromZip('testuser/fake-banner', $zipPath));
    }

    /**
     * The uploader reads every image's header now, so placeholder strings no longer pass.
     */
    private function imageBytes(string $format = 'png', int $width = 4, int $height = 4): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        ob_start();
        match ($format) {
            'jpg' => imagejpeg($image),
            'gif' => imagegif($image),
            'webp' => imagewebp($image),
            default => imagepng($image),
        };
        $bytes = ob_get_clean();

        imagedestroy($image);
        self::assertIsString($bytes);

        return $bytes;
    }

    /**
     * A PNG header claiming huge dimensions with no pixel data behind it — a decompression bomb,
     * and small enough to slip under the byte limit.
     */
    private function oversizedImageHeader(int $width = 100_000, int $height = 100_000): string
    {
        $ihdr = 'IHDR'.pack('N2', $width, $height)."\x08\x02\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
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
