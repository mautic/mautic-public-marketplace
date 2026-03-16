<?php

declare(strict_types=1);

namespace App\Tests\Formatter;

use App\Formatter\RelativeTimeFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\IniFileLoader;
use Symfony\Component\Translation\Translator;

final class RelativeTimeFormatterTest extends TestCase
{
    private RelativeTimeFormatter $formatter;

    protected function setUp(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('ini', new IniFileLoader());
        $translator->addResource('ini', dirname(__DIR__, 2).'/translations/messages.en.ini', 'en');

        $this->formatter = new RelativeTimeFormatter($translator);
    }

    public function testFormatsDays(): void
    {
        self::assertSame(
            '5 days ago',
            $this->formatter->formatAgo(
                new \DateTimeImmutable('2026-03-11'),
                new \DateTimeImmutable('2026-03-16'),
            ),
        );
    }

    public function testFormatsWeeks(): void
    {
        self::assertSame(
            '2 weeks ago',
            $this->formatter->formatAgo(
                new \DateTimeImmutable('2026-03-02'),
                new \DateTimeImmutable('2026-03-16'),
            ),
        );
    }

    public function testFormatsCalendarMonths(): void
    {
        self::assertSame(
            '1 month ago',
            $this->formatter->formatAgo(
                new \DateTimeImmutable('2026-02-16'),
                new \DateTimeImmutable('2026-03-16'),
            ),
        );
    }

    public function testFormatsYears(): void
    {
        self::assertSame(
            '2 years ago',
            $this->formatter->formatAgo(
                new \DateTimeImmutable('2024-01-10'),
                new \DateTimeImmutable('2026-03-16'),
            ),
        );
    }

    public function testClampsFutureDates(): void
    {
        self::assertSame(
            '0 days ago',
            $this->formatter->formatAgo(
                new \DateTimeImmutable('2026-03-20'),
                new \DateTimeImmutable('2026-03-16'),
            ),
        );
    }
}
