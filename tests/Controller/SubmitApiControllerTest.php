<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Auth0\Auth0User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SubmitApiControllerTest extends WebTestCase
{
    public function testSubmitWithoutAuthorizationHeader(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/submit', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'asset_url' => 'https://example.com/asset/campaign.zip',
        ]));

        self::assertResponseStatusCodeSame(401);
    }

    public function testSubmitWithNonBearerAuthorization(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/submit', server: [
            'CONTENT_TYPE' => 'application/json',
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
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], content: json_encode([
            'asset_url' => '',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testSubmitWithInvalidUrl(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/submit', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], content: json_encode([
            'asset_url' => 'not-a-url',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testSubmitWithHttpUrl(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/submit', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], content: json_encode([
            'asset_url' => 'http://example.com/asset/campaign.zip',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testSubmitWithMissingAssetUrl(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/submit', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], content: json_encode([]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testSuccessfulSubmit(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/submit', server: [
            'CONTENT_TYPE' => 'application/json',
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
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], content: 'not-json');

        self::assertResponseStatusCodeSame(400);
    }

    public function testPublishPageRendersWithAssetUrl(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/publish?asset_url=https://example.com/asset/campaign.zip');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Publish Campaign to Marketplace');
    }

    public function testPublishPageWithoutAssetUrlRedirectsToMarketplace(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/publish');

        self::assertResponseRedirects('/browse');
    }

    public function testPublishPageAnonymousRedirectsToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/publish?asset_url=https://example.com/asset/campaign.zip');

        self::assertResponseRedirects('/auth/login?returnTo=/publish?asset_url%3Dhttps://example.com/asset/campaign.zip');
    }
}
