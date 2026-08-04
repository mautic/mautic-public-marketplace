<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Security\CspNonceGenerator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CspExtension extends AbstractExtension
{
    public function __construct(
        private readonly CspNonceGenerator $nonceGenerator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            // Deliberately not html-safe: it goes in an attribute and should be escaped.
            new TwigFunction('csp_nonce', [$this->nonceGenerator, 'nonce']),
        ];
    }
}
