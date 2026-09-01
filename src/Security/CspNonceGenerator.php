<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The nonce that links the CSP header to the inline <script> tags we emit.
 *
 * Cached on the Request, not on this service: the service is shared across requests, and a
 * reused nonce is no better than 'unsafe-inline'.
 */
final class CspNonceGenerator
{
    private const REQUEST_ATTRIBUTE = '_csp_nonce';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function nonce(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            // CLI, or a template rendered outside HTTP: nobody will read the header either.
            return $this->generate();
        }

        $nonce = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (!\is_string($nonce)) {
            $nonce = $this->generate();
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $nonce);
        }

        return $nonce;
    }

    private function generate(): string
    {
        return base64_encode(random_bytes(16));
    }
}
