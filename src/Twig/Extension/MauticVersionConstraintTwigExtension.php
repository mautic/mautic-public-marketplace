<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Formatter\MauticVersionConstraintFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class MauticVersionConstraintTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly MauticVersionConstraintFormatter $formatter,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('mautic_version_label', [$this, 'formatConstraint']),
            new TwigFilter('mautic_version_labels', [$this, 'formatConstraints']),
            new TwigFilter('mautic_version_tag_data', [$this, 'formatTagData']),
        ];
    }

    public function formatConstraint(?string $constraint): string
    {
        if (null === $constraint) {
            return '';
        }

        return $this->formatter->format($constraint);
    }

    /**
     * @return list<string>
     */
    public function formatConstraints(?string $constraints): array
    {
        if (null === $constraints || '' === trim($constraints)) {
            return [];
        }

        return $this->formatter->formatAll($constraints);
    }

    /**
     * @return list<array{constraint: string, label: string}>
     */
    public function formatTagData(?string $constraints): array
    {
        if (null === $constraints || '' === trim($constraints)) {
            return [];
        }

        return $this->formatter->formatTagData($constraints);
    }
}
