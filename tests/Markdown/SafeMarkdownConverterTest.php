<?php

namespace App\Tests\Markdown;

use App\Markdown\SafeMarkdownConverter;
use PHPUnit\Framework\TestCase;

final class SafeMarkdownConverterTest extends TestCase
{
    public function testItEscapesRawHtmlAndPromotesLowLevelHeadings(): void
    {
        $converter = new SafeMarkdownConverter();

        $html = $converter->convert("# Title\n\n## Subtitle\n\n### Section\n\n<script>alert('x')</script>");

        self::assertStringContainsString('<h3>Title</h3>', $html);
        self::assertStringContainsString('<h3>Subtitle</h3>', $html);
        self::assertStringContainsString('<h3>Section</h3>', $html);
        self::assertStringNotContainsString('<h1>', $html);
        self::assertStringNotContainsString('<h2>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testItBlocksUnsafeLinks(): void
    {
        $converter = new SafeMarkdownConverter();

        $html = $converter->convert('[Bad](javascript:alert(1))');

        self::assertStringNotContainsString('javascript:alert(1)', $html);
    }
}
