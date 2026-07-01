<?php

declare(strict_types=1);

namespace App\Tests\Marketplace;

use App\Marketplace\ComposerJsonReader;
use App\Marketplace\Exception\SubmitValidationException;
use App\Tests\Mock\PackageZipFactory;
use PHPUnit\Framework\TestCase;

final class ComposerJsonReaderTest extends TestCase
{
    public function testReadReturnsComposerData(): void
    {
        $zip = PackageZipFactory::create();

        try {
            $data = (new ComposerJsonReader())->read($zip);
        } finally {
            unlink($zip);
        }

        self::assertSame('testuser/test-campaign', $data['name']);
        self::assertSame('mautic-resource', $data['type']);
        self::assertSame('1.0.0', $data['version']);
    }

    public function testReadThrowsWhenZipIsInvalid(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'invalid_zip_');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, 'not a zip');

        $this->expectException(SubmitValidationException::class);

        try {
            (new ComposerJsonReader())->read($tmp);
        } finally {
            unlink($tmp);
        }
    }

    public function testReadThrowsWhenComposerJsonMissing(): void
    {
        $zip = PackageZipFactory::create(omitComposer: true);

        $this->expectException(SubmitValidationException::class);

        try {
            (new ComposerJsonReader())->read($zip);
        } finally {
            unlink($zip);
        }
    }

    public function testValidateAcceptsValidComposerData(): void
    {
        (new ComposerJsonReader())->validate([
            'name' => 'testuser/test-plugin',
            'type' => 'mautic-plugin',
            'version' => '1.0.0',
        ]);

        $this->expectNotToPerformAssertions();
    }

    public function testValidateRejectsInvalidName(): void
    {
        $this->expectException(SubmitValidationException::class);

        (new ComposerJsonReader())->validate([
            'name' => 'NOT VALID',
            'type' => 'mautic-plugin',
            'version' => '1.0.0',
        ]);
    }

    public function testValidateRejectsUnknownType(): void
    {
        $this->expectException(SubmitValidationException::class);

        (new ComposerJsonReader())->validate([
            'name' => 'testuser/test-plugin',
            'type' => 'library',
            'version' => '1.0.0',
        ]);
    }

    public function testExtractMauticVersionReturnsRawConstraint(): void
    {
        $smv = (new ComposerJsonReader())->extractMauticVersion([
            'require' => ['mautic/core-lib' => '^5.0'],
        ]);

        self::assertSame('^5.0', $smv);
    }

    public function testExtractMauticVersionPreservesCompoundConstraint(): void
    {
        $smv = (new ComposerJsonReader())->extractMauticVersion([
            'require' => ['mautic/core-lib' => '^5.0 || ^4.4'],
        ]);

        self::assertSame('^5.0 || ^4.4', $smv);
    }

    public function testExtractMauticVersionFallsBackToExtra(): void
    {
        $smv = (new ComposerJsonReader())->extractMauticVersion([
            'require' => ['some/other-package' => '^1.0'],
            'extra' => ['mautic' => ['minimum-version' => '5.0']],
        ]);

        self::assertSame('5.0', $smv);
    }

    public function testExtractMauticVersionReturnsNullWhenAbsent(): void
    {
        $smv = (new ComposerJsonReader())->extractMauticVersion([
            'require' => ['some/other-package' => '^1.0'],
        ]);

        self::assertNull($smv);
    }

    public function testNormalizeVersionPadsToFourSegments(): void
    {
        $reader = new ComposerJsonReader();

        self::assertSame('1.0.0.0', $reader->normalizeVersion('1.0'));
        self::assertSame('5.2.1.0', $reader->normalizeVersion('v5.2.1'));
        self::assertSame('6.0.0.0', $reader->normalizeVersion('6.0.0'));
    }
}
