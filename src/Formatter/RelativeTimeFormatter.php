<?php

declare(strict_types=1);

namespace App\Formatter;

use Symfony\Contracts\Translation\TranslatorInterface;

final class RelativeTimeFormatter
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function formatAgo(\DateTimeInterface|string|null $dateTime, ?\DateTimeInterface $reference = null): string
    {
        if (null === $dateTime || '' === $dateTime) {
            return '';
        }

        $updatedAt = \is_string($dateTime) ? new \DateTimeImmutable($dateTime) : \DateTimeImmutable::createFromInterface($dateTime);
        $referenceDate = null === $reference ? new \DateTimeImmutable() : \DateTimeImmutable::createFromInterface($reference);

        if ($updatedAt > $referenceDate) {
            $updatedAt = $referenceDate;
        }

        $diff = $updatedAt->diff($referenceDate);

        if ($diff->y > 0) {
            return $this->translateUnit('year', $diff->y);
        }

        if ($diff->m > 0) {
            return $this->translateUnit('month', $diff->m);
        }

        $weeks = intdiv($diff->days, 7);
        if ($weeks > 0) {
            return $this->translateUnit('week', $weeks);
        }

        return $this->translateUnit('day', max(0, $diff->days));
    }

    private function translateUnit(string $unit, int $count): string
    {
        $key = sprintf(
            'mautic.marketplace.detail.relative.%s',
            1 === $count ? $unit : sprintf('%ss', $unit),
        );

        return $this->translator->trans($key, ['%count%' => $count]);
    }
}
