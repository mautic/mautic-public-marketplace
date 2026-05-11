<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Auth0\Auth0User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProfileControllerTest extends WebTestCase
{
    public function testAnonymousProfileRedirectsToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/profile');

        self::assertResponseRedirects('/auth/login?returnTo=/profile');
    }

    public function testAuthenticatedProfileRendersUserReviewsAndPackages(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#profile-content');
        self::assertSelectorTextContains('h2', 'Test User');
        self::assertSelectorTextContains('body', 'test@example.com');
        self::assertSelectorTextContains('body', 'Your reviews');
        self::assertSelectorTextContains('body', 'Great plugin!');
        self::assertSelectorTextContains('#profile-download-history-tab', 'Download history');
        self::assertSelectorExists('#profile-download-history-tab.active');
        self::assertSelectorTextContains('#profile-uploaded-packages-tab', 'Uploaded packages');
        self::assertSelectorTextContains('#profile-download-history', 'Alpha Plugin');
        self::assertSelectorTextContains('#profile-download-history', 'Version 1.0.0');
        self::assertSelectorExists('#profile-download-history a[href="/package/mautic/alpha-plugin"]');
        self::assertSelectorTextContains('body', 'Example Plugin');
        self::assertSelectorExists('#profile-uploaded-packages a[href="/package/mautic/example-plugin"]');
        self::assertSelectorExists('a[href="/auth/logout"]');
    }
}
