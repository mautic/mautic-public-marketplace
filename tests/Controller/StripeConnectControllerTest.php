<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Auth0\Auth0User;
use App\Tests\Mock\SupabaseMockHttpClient;
use App\Tests\Support\ConfiguresStripe;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StripeConnectControllerTest extends WebTestCase
{
    use ConfiguresStripe;

    protected function tearDown(): void
    {
        $this->restoreStripe();

        parent::tearDown();
    }

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

    public function testOnboardCreatesTheAccountAndRedirectsToStripeHostedOnboarding(): void
    {
        $this->enableStripe();
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/stripe/connect/onboard');

        self::assertResponseRedirects('https://connect.stripe.com/setup/s/test');

        // The id is stored straight away, so an abandoned onboarding can be resumed
        // instead of leaving a stray connected account behind on every attempt.
        $stored = self::getContainer()->get(SupabaseMockHttpClient::class)->recordedConnectAccounts;
        self::assertCount(1, $stored);
        self::assertSame('auth0|test123', $stored[0]['auth0_user_id']);
        self::assertSame('acct_test_123', $stored[0]['stripe_account_id']);
        self::assertFalse($stored[0]['details_submitted']);
    }

    public function testOnboardReusesTheAccountAVendorAlreadyHas(): void
    {
        $this->enableStripe();
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|vendor', 'Vendor', 'vendor@example.com', null), 'main');
        $client->request('GET', '/stripe/connect/onboard');

        self::assertResponseRedirects('https://connect.stripe.com/setup/s/test');
        // Only a fresh account link — creating a second connected account would orphan
        // the vendor's existing one along with everything already paid into it.
        self::assertSame(0, $this->stripeRequestCount('/v1/accounts'));
        self::assertSame(1, $this->stripeRequestCount('/v1/account_links'));
    }

    public function testOnboardingReturnStoresTheAccountStatus(): void
    {
        $this->enableStripe();
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|vendor', 'Vendor', 'vendor@example.com', null), 'main');
        $client->request('GET', '/stripe/connect/return');

        self::assertResponseRedirects('/profile');

        $stored = self::getContainer()->get(SupabaseMockHttpClient::class)->recordedConnectAccounts;
        self::assertCount(1, $stored);
        self::assertSame('acct_test_123', $stored[0]['stripe_account_id']);
        self::assertTrue($stored[0]['charges_enabled']);
        self::assertTrue($stored[0]['details_submitted']);
    }

    public function testOnboardingReturnDoesNothingForAVendorWhoNeverStarted(): void
    {
        $this->enableStripe();
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/stripe/connect/return');

        self::assertResponseRedirects('/profile');
        self::assertSame([], self::getContainer()->get(SupabaseMockHttpClient::class)->recordedConnectAccounts);
    }

    public function testRefreshSendsTheVendorBackThroughOnboarding(): void
    {
        // Account Links are single-use and expire; Stripe calls the refresh URL then.
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|vendor', 'Vendor', 'vendor@example.com', null), 'main');
        $client->request('GET', '/stripe/connect/refresh');

        self::assertResponseRedirects('/stripe/connect/onboard');
    }
}
