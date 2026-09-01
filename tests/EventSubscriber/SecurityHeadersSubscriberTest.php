<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityHeadersSubscriberTest extends WebTestCase
{
    public function testResponsesCarrySecurityHeaders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        $headers = $client->getResponse()->headers;
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=()', $headers->get('Permissions-Policy'));

        $csp = (string) $headers->get('Content-Security-Policy');
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("base-uri 'self'", $csp);
        self::assertStringContainsString("frame-ancestors 'self'", $csp);
    }

    /**
     * A mismatch between header and markup takes the page's whole JavaScript down.
     */
    public function testInlineScriptsCarryTheNonceFromTheCspHeader(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertSame(1, preg_match("/script-src [^;]*'nonce-([^']+)'/", $csp, $matches), 'script-src must carry a nonce.');

        $nonce = $matches[1];
        $importMap = $crawler->filter('script[type="importmap"]');

        self::assertCount(1, $importMap);
        self::assertSame($nonce, $importMap->attr('nonce'));
    }

    public function testEachResponseGetsItsOwnNonce(): void
    {
        $client = self::createClient();

        $client->request('GET', '/');
        $first = (string) $client->getResponse()->headers->get('Content-Security-Policy');

        $client->request('GET', '/');
        $second = (string) $client->getResponse()->headers->get('Content-Security-Policy');

        self::assertNotSame($first, $second, 'A reused nonce is no better than no nonce.');
    }

    /**
     * A redirect is the first thing an unauthenticated visitor hits on the upload flow.
     *
     * The one uncovered case is Symfony's own exception page, where ErrorListener::removeCspHeader()
     * strips the header so it can render. That's behind the debug flag — dev and test only.
     */
    public function testNonSuccessfulResponsesAreAlsoCovered(): void
    {
        $client = self::createClient();
        $client->request('GET', '/upload/package');

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString(
            "default-src 'self'",
            (string) $client->getResponse()->headers->get('Content-Security-Policy'),
        );
    }

    public function testHstsIsOnlySentOverHttps(): void
    {
        $client = self::createClient();

        $client->request('GET', '/');
        self::assertFalse($client->getResponse()->headers->has('Strict-Transport-Security'));

        $client->request('GET', 'https://localhost/');
        self::assertSame('max-age=31536000; includeSubDomains', $client->getResponse()->headers->get('Strict-Transport-Security'));
    }
}
