<?php

declare(strict_types=1);

namespace App\Marketplace;

/**
 * Strips HTML from short, plain-text package metadata before it is persisted.
 *
 * Package metadata (display name, headline, keywords, versions, gallery captions…)
 * is never meant to contain markup, but it reaches templates that render some of it
 * with |raw or by string concatenation into HTML attributes. Sanitising on input is
 * the primary defence against stored XSS from both the upload form and an uploaded
 * composer.json; output escaping (Twig autoescape / |e) is the second layer.
 *
 * Note: free-text description is intentionally NOT handled here. It is rendered
 * through the CommonMark converter with html_input=escape, which already neutralises
 * markup, and stripping tags would corrupt legitimate markdown.
 */
final class MetadataSanitizer
{
    /**
     * Remove any HTML tags and collapse surrounding whitespace.
     */
    public function text(?string $value): string
    {
        if (null === $value) {
            return '';
        }

        // strip_tags removes complete tags but leaves stray angle brackets from
        // malformed markup (e.g. an unmatched `">`). These short metadata fields
        // never legitimately contain `<`/`>`, so drop any that survive.
        return trim(str_replace(['<', '>'], '', strip_tags($value)));
    }

    /**
     * Sanitise every entry of a list of strings, dropping any that become empty.
     *
     * @param list<string> $values
     *
     * @return list<string>
     */
    public function textList(array $values): array
    {
        $sanitized = [];
        foreach ($values as $value) {
            $clean = $this->text($value);
            if ('' !== $clean) {
                $sanitized[] = $clean;
            }
        }

        return $sanitized;
    }
}
