<?php

declare(strict_types=1);

namespace App\Tests\Marketplace;

use App\Marketplace\MetadataSanitizer;
use PHPUnit\Framework\TestCase;

final class MetadataSanitizerTest extends TestCase
{
    private MetadataSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new MetadataSanitizer();
    }

    public function testStripsHtmlTagsFromText(): void
    {
        self::assertSame('XSS test 1', $this->sanitizer->text('XSS test 1<img src onerror=alert(2)>'));
    }

    public function testRemovesTagsAndStrayAngleBrackets(): void
    {
        // strip_tags removes the complete <img> tag; the stray `">` residue from the
        // malformed attribute break must not survive with its angle bracket.
        $clean = $this->sanitizer->text('2.0.1<img src onerror=alert(13)>" onerror=alert(14)>');
        self::assertStringNotContainsString('<', $clean);
        self::assertStringNotContainsString('>', $clean);
        self::assertStringStartsWith('2.0.1', $clean);
    }

    public function testKeepsInterTagTextAsInertPlainText(): void
    {
        // strip_tags keeps text between tags; it is harmless once output is escaped.
        self::assertSame('alert(1)', $this->sanitizer->text('<script>alert(1)</script>'));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('hello', $this->sanitizer->text("  <b>hello</b>\n"));
    }

    public function testNullBecomesEmptyString(): void
    {
        self::assertSame('', $this->sanitizer->text(null));
    }

    public function testKeepsPlainText(): void
    {
        self::assertSame('7.0', $this->sanitizer->text('7.0'));
    }

    public function testTextListSanitizesEachEntryAndDropsEmpties(): void
    {
        self::assertSame(
            ['en', '7.0'],
            $this->sanitizer->textList([
                'en<img src onerror=alert(6)>',
                '<img src onerror=alert(1)>',
                '7.0',
            ]),
        );
    }
}
