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
        self::assertSelectorTextContains('body', 'My packages');
        self::assertSelectorTextContains('body', 'Example Plugin');
        self::assertSelectorExists('a[href="/auth/logout"]');
    }
}
