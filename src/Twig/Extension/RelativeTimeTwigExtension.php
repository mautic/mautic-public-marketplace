<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Formatter\RelativeTimeFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class RelativeTimeTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly RelativeTimeFormatter $relativeTimeFormatter,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('relative_time_ago', [$this, 'formatRelativeTimeAgo']),
        ];
    }

    public function formatRelativeTimeAgo(\DateTimeInterface|string|null $dateTime): string
    {
        return $this->relativeTimeFormatter->formatAgo($dateTime);
    }
}
