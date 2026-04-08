<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProfileApiControllerTest extends WebTestCase
{
    public function testGetProfileWithoutAuthorizationHeader(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/profile');

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('Missing or invalid Authorization header', (string) $client->getResponse()->getContent());
    }

    public function testGetProfileWithNonBearerAuthorization(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/profile', server: [
            'HTTP_AUTHORIZATION' => 'Basic abc123',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testPostMethodNotAllowed(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/profile');

        self::assertResponseStatusCodeSame(405);
    }

    public function testGetProfileWithValidTokenReturnsHtml(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/profile', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ]);

        self::assertResponseIsSuccessful();

        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Test User', $content);
        self::assertStringContainsString('test@example.com', $content);
    }

    public function testGetProfileReturnsUserReviews(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/profile', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ]);

        self::assertResponseIsSuccessful();

        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('mautic/example-plugin', $content);
        self::assertStringContainsString('mautic/zebra-theme', $content);
        self::assertStringContainsString('Great plugin!', $content);
    }

    public function testGetProfileWithNoReviewsShowsEmptyMessage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/profile', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ]);

        self::assertResponseIsSuccessful();

        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('My Reviews', $content);
    }
}
