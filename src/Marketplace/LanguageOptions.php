<?php

declare(strict_types=1);

namespace App\Marketplace;

use Symfony\Component\Intl\Languages;

/**
 * The full ISO 639 language list (from symfony/intl) used to populate the
 * package upload "Languages" multiselect, so authors aren't limited to a short
 * hand-maintained set. Values are language codes; labels are the English names.
 */
final class LanguageOptions
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function all(): array
    {
        $options = [];
        foreach (Languages::getNames() as $code => $name) {
            $options[] = ['value' => $code, 'label' => $name];
        }

        usort($options, static fn (array $a, array $b): int => $a['label'] <=> $b['label']);

        return $options;
    }
}
