<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Auth0\Auth0User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StripeCheckoutControllerTest extends WebTestCase
{
    public function testBuyRedirectsAnonymousUsersToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/package/mautic/zebra-theme/buy');

        self::assertResponseRedirects('/auth/login?returnTo=/package/mautic/zebra-theme/buy');
    }

    public function testBuyRedirectsToDetailWhenPackageNotPurchasable(): void
    {
        // The paid package has no Stripe price yet (and the test env has no key), so
        // checkout cannot start and the buyer is sent back to the detail page.
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/package/mautic/zebra-theme/buy');

        self::assertResponseRedirects('/package/mautic/zebra-theme');
    }

    public function testWebhookRejectsRequestWithoutValidSignature(): void
    {
        $client = self::createClient();
        $client->request('POST', '/stripe/webhook', server: ['HTTP_Stripe-Signature' => 'bogus'], content: '{}');

        self::assertResponseStatusCodeSame(400);
    }
}
