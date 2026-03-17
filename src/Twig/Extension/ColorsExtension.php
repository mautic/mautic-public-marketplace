<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ColorsExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('contrast_color', [$this, 'getContrastColor'], ['is_safe' => ['html']]),
        ];
    }

    public function getContrastColor(string $hexColor): string
    {
        $hexColor = ltrim($hexColor, '#');

        if (3 === strlen($hexColor)) {
            $hexColor = str_repeat($hexColor[0], 2).str_repeat($hexColor[1], 2).str_repeat($hexColor[2], 2);
        }

        if (!preg_match('/^[0-9A-Fa-f]{6}$/', $hexColor)) {
            return 'black';
        }

        $r = hexdec(substr($hexColor, 0, 2));
        $g = hexdec(substr($hexColor, 2, 2));
        $b = hexdec(substr($hexColor, 4, 2));
        $brightness = ($r * 299 + $g * 587 + $b * 114) / 1000;

        return $brightness > 125 ? 'black' : 'white';
    }
}
