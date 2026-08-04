<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\CspNonceGenerator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets baseline security headers on every response unless already present,
 * so a controller can still override them.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];

    public function __construct(
        private readonly CspNonceGenerator $nonceGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        foreach (self::HEADERS as $name => $value) {
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        if (!$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        // HSTS only over HTTPS; .htaccess already redirects plain HTTP there.
        if ($event->getRequest()->isSecure() && !$headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }

    /**
     * script-src does the real work here: an injected string stays inert even if it slips past
     * escaping, since only same-origin files and this request's nonce may run.
     *
     * style-src still needs 'unsafe-inline' — the Carbon components set custom properties per
     * element (`style="--card-group--cards-in-row: 3"`), which no nonce or hash can cover. An
     * accepted gap; CSS injection is a much smaller prize than script injection.
     */
    private function contentSecurityPolicy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            // ga.jspm.io is AssetMapper's es-module-shims fallback for browsers without import
            // maps. Symfony pins it with an SRI hash, so the host can't swap in other code.
            \sprintf("script-src 'self' 'nonce-%s' https://ga.jspm.io", $this->nonceGenerator->nonce()),
            "style-src 'self' 'unsafe-inline'",
            // Banners come from Supabase storage and avatars from whatever host Auth0 hands back,
            // so we can't enumerate the image origins.
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            // The browser only calls this app; Supabase is reached server-side.
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }
}
