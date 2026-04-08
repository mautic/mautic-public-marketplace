<?php

declare(strict_types=1);

namespace App\Formatter;

final class LanguageFilterFormatter
{
    public function canonicalize(?string $language): ?string
    {
        if (null === $language) {
            return null;
        }

        $normalized = strtolower(trim($language));
        if ('' === $normalized) {
            return null;
        }

        return match ($normalized) {
            'en', 'en-us', 'en-gb', 'english' => 'english',
            'nl', 'nl-nl', 'dutch', 'nederlands' => 'dutch',
            default => $normalized,
        };
    }

    public function formatLabel(string $language): string
    {
        $canonical = $this->canonicalize($language);
        if (null === $canonical) {
            return '';
        }

        return match ($canonical) {
            'english' => 'English',
            'dutch' => 'Dutch',
            default => $this->humanize($language),
        };
    }

    /**
     * @param list<string> $languages
     *
     * @return array<string, string>
     */
    public function buildOptions(array $languages): array
    {
        $options = [];

        foreach ($languages as $language) {
            $canonical = $this->canonicalize($language);
            if (null === $canonical || \array_key_exists($canonical, $options)) {
                continue;
            }

            $options[$canonical] = $this->formatLabel($language);
        }

        asort($options);

        return $options;
    }

    private function humanize(string $language): string
    {
        $normalized = trim($language);
        $normalized = preg_replace('/[_-]+/', ' ', $normalized) ?? $normalized;

        return ucwords(strtolower($normalized));
    }
}
