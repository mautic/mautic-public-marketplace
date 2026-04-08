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

    public function testGetProfileWithValidTokenReturnsUserInfo(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/profile', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ]);

        self::assertResponseStatusCodeSame(200);

        $content = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertArrayHasKey('user', $content);
        self::assertSame('auth0|test123', $content['user']['sub']);
        self::assertSame('Test User', $content['user']['name']);
        self::assertSame('test@example.com', $content['user']['email']);
    }

    public function testGetProfileReturnsUserReviews(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/profile', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ]);

        self::assertResponseStatusCodeSame(200);

        $content = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertArrayHasKey('reviews', $content);
        self::assertCount(2, $content['reviews']);
        self::assertSame('mautic/example-plugin', $content['reviews'][0]['objectId']);
        self::assertSame(5, $content['reviews'][0]['rating']);
        self::assertSame('mautic/zebra-theme', $content['reviews'][1]['objectId']);
    }

    public function testGetProfileReturnsUploadedPackages(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/profile', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ]);

        self::assertResponseStatusCodeSame(200);

        $content = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertArrayHasKey('uploaded_packages', $content);
        self::assertIsArray($content['uploaded_packages']);
    }

    public function testGetProfileResponseStructure(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/profile', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ]);

        self::assertResponseStatusCodeSame(200);

        $content = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertArrayHasKey('user', $content);
        self::assertArrayHasKey('reviews', $content);
        self::assertArrayHasKey('uploaded_packages', $content);

        self::assertArrayHasKey('sub', $content['user']);
        self::assertArrayHasKey('name', $content['user']);
        self::assertArrayHasKey('email', $content['user']);
        self::assertArrayHasKey('picture', $content['user']);
    }
}
