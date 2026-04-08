<?php

declare(strict_types=1);

namespace App\Formatter;

use Symfony\Contracts\Translation\TranslatorInterface;

final class MauticVersionConstraintFormatter
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function format(string $constraint): string
    {
        $constraint = trim($constraint);
        if ('' === $constraint) {
            return '';
        }

        if ('*' === $constraint) {
            return $this->translator->trans('marketplace.mautic_version.any');
        }

        if (preg_match('/^\^(\d+)\.(\d+)(?:\.\d+)?$/', $constraint, $matches)) {
            return $this->translator->trans('marketplace.mautic_version.caret', [
                '%major%' => $matches[1],
                '%minor%' => $matches[2],
            ]);
        }

        if (preg_match('/^~(\d+)\.(\d+)(?:\.\d+)?$/', $constraint, $matches)) {
            return $this->translator->trans('marketplace.mautic_version.tilde', [
                '%major%' => $matches[1],
                '%minor%' => $matches[2],
            ]);
        }

        if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $constraint, $matches)) {
            return $this->translator->trans('marketplace.mautic_version.exact', [
                '%major%' => $matches[1],
                '%minor%' => $matches[2],
                '%patch%' => $matches[3],
            ]);
        }

        if (preg_match('/^>=(\d+)\.(\d+)(?:\.\d+)?$/', $constraint, $matches)) {
            return $this->translator->trans('marketplace.mautic_version.gte', [
                '%major%' => $matches[1],
                '%minor%' => $matches[2],
            ]);
        }

        return $constraint;
    }

    /**
     * @return list<string>
     */
    public function split(string $constraints): array
    {
        $parts = preg_split('/\s*\|\|\s*/', trim($constraints)) ?: [];
        $parts = array_map(static fn (string $part): string => trim($part), $parts);

        return array_values(array_filter($parts, static fn (string $part): bool => '' !== $part));
    }

    /**
     * @return list<string>
     */
    public function formatAll(string $constraints): array
    {
        $formatted = array_map(fn (string $constraint): string => $this->format($constraint), $this->split($constraints));

        return array_values(array_unique($formatted));
    }

    /**
     * @return list<array{constraint: string, label: string}>
     */
    public function formatTagData(string $constraints): array
    {
        $tagData = [];

        foreach ($this->split($constraints) as $constraint) {
            $tagData[] = [
                'constraint' => $constraint,
                'label' => $this->format($constraint),
            ];
        }

        return $tagData;
    }
}
