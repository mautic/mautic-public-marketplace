<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Auth0\Auth0User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SubmitApiControllerTest extends WebTestCase
{
    public function testAnonymousSubmitRedirectsToLogin(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/submit', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'asset_url' => 'https://example.com/asset/campaign.zip',
        ]));

        self::assertResponseRedirects();
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
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('POST', '/api/package/submit', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'asset_url' => '',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testSubmitWithInvalidUrl(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('POST', '/api/package/submit', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'asset_url' => 'not-a-url',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testSubmitWithHttpUrl(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('POST', '/api/package/submit', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'asset_url' => 'http://example.com/asset/campaign.zip',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testSubmitWithMissingAssetUrl(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('POST', '/api/package/submit', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testSuccessfulSubmit(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('POST', '/api/package/submit', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'asset_url' => 'https://example.com/asset/campaign.zip',
        ]));

        self::assertResponseStatusCodeSame(201);

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('testuser/test-campaign', $data['package_name']);
        self::assertSame('1.0.0', $data['version']);
        self::assertTrue($data['created']);
    }

    public function testSubmitWithUnreachableAssetUrlReturnsJsonError(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('POST', '/api/package/submit', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'asset_url' => 'https://unreachable.test/campaign.zip',
        ]));

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/json');
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('Could not reach asset URL', $data['error']);
    }

    public function testSubmitWithUnknownVendorIsRejected(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('POST', '/api/package/submit', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'asset_url' => 'https://example.com/asset/unknown-vendor.zip',
        ]));

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('missing a vendor name', $data['error']);
    }

    public function testSubmitWithHtmlPageReturnsClearError(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('POST', '/api/package/submit', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'asset_url' => 'https://example.com/asset/not-a-zip.zip',
        ]));

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/json');
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('did not return a downloadable ZIP file', $data['error']);
    }

    public function testSubmitWithInvalidJson(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('POST', '/api/package/submit', server: [
            'CONTENT_TYPE' => 'application/json',
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
