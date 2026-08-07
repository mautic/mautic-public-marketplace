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
        self::assertSame("frame-ancestors 'self'", $headers->get('Content-Security-Policy'));
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
