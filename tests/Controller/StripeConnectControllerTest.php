<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Auth0\Auth0User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StripeConnectControllerTest extends WebTestCase
{
    public function testOnboardRedirectsAnonymousUsersToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/stripe/connect/onboard');

        self::assertResponseRedirects('/auth/login?returnTo=/stripe/connect/onboard');
    }

    public function testOnboardRedirectsToProfileWhenStripeNotConfigured(): void
    {
        // The test environment has no STRIPE_SECRET_KEY, so onboarding cannot start
        // and the vendor is sent back to the profile with a flash message.
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/stripe/connect/onboard');

        self::assertResponseRedirects('/profile');
    }
}
