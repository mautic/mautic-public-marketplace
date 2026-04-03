<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SubmitApiControllerTest extends WebTestCase
{
    public function testSubmitWithoutAuthorizationHeader(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/submit', content: json_encode([
            'asset_url' => 'https://example.com/asset/campaign.zip',
        ]));

        self::assertResponseStatusCodeSame(401);
    }

    public function testSubmitWithNonBearerAuthorization(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/submit', server: [
            'HTTP_AUTHORIZATION' => 'Basic abc123',
        ], content: json_encode([
            'asset_url' => 'https://example.com/asset/campaign.zip',
        ]));

        self::assertResponseStatusCodeSame(401);
    }

    public function testGetMethodNotAllowed(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/package/submit');

        self::assertResponseStatusCodeSame(405);
    }

    public function testSubmitWithEmptyAssetUrl(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/submit', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], content: json_encode([
            'asset_url' => '',
        ]));

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Asset URL is required', (string) $client->getResponse()->getContent());
    }

    public function testSubmitWithInvalidUrl(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/submit', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], content: json_encode([
            'asset_url' => 'not-a-url',
        ]));

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Asset URL must be a valid URL', (string) $client->getResponse()->getContent());
    }

    public function testSubmitWithHttpUrl(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/submit', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], content: json_encode([
            'asset_url' => 'http://example.com/asset/campaign.zip',
        ]));

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Asset URL must use HTTPS', (string) $client->getResponse()->getContent());
    }

    public function testSubmitWithMissingAssetUrl(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/submit', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], content: json_encode([]));

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Asset URL is required', (string) $client->getResponse()->getContent());
    }

    public function testSuccessfulSubmit(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/submit', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], content: json_encode([
            'asset_url' => 'https://example.com/asset/campaign.zip',
        ]));

        self::assertResponseStatusCodeSame(201);

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('testuser/test-campaign', $data['package_name']);
        self::assertSame('1.0.0', $data['version']);
        self::assertTrue($data['created']);
    }

    public function testSubmitWithInvalidJson(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/submit', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], content: 'not-json');

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Could not decode request body', (string) $client->getResponse()->getContent());
    }

    public function testPublishPageRendersWithAssetUrl(): void
    {
        $client = self::createClient();
        $client->request('GET', '/publish?asset_url=https://example.com/asset/campaign.zip');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Publish Campaign to Marketplace');
    }

    public function testPublishPageRendersWithoutAssetUrl(): void
    {
        $client = self::createClient();
        $client->request('GET', '/publish');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.content-block__subheading', 'No asset URL provided');
    }
}
