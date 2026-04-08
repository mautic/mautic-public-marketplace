<?php

declare(strict_types=1);

namespace App\Tests\Formatter;

use App\Formatter\MauticVersionConstraintFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class MauticVersionConstraintFormatterTest extends TestCase
{
    public function testFormatsKnownConstraintPatterns(): void
    {
        $formatter = $this->createFormatter();

        self::assertSame('v4.4+', $formatter->format('^4.4.0'));
        self::assertSame('v4.4 (Stable)', $formatter->format('~4.4.0'));
        self::assertSame('v4.4.2 only', $formatter->format('4.4.2'));
        self::assertSame('v4.0 and up', $formatter->format('>=4.0.0'));
        self::assertSame('Any version', $formatter->format('*'));
    }

    public function testFormatsCompoundConstraints(): void
    {
        $formatter = $this->createFormatter();

        self::assertSame(['v4.4+', 'v5.0+'], $formatter->formatAll('^4.4.0 || ^5.0.0'));
    }

    private function createFormatter(): MauticVersionConstraintFormatter
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'marketplace.mautic_version.any' => 'Any version',
            'marketplace.mautic_version.caret' => 'v%major%.%minor%+',
            'marketplace.mautic_version.tilde' => 'v%major%.%minor% (Stable)',
            'marketplace.mautic_version.exact' => 'v%major%.%minor%.%patch% only',
            'marketplace.mautic_version.gte' => 'v%major%.%minor% and up',
        ], 'en');

        return new MauticVersionConstraintFormatter($translator);
    }
}
