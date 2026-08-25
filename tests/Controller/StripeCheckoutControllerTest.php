<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Auth0\Auth0User;
use App\Tests\Mock\SupabaseMockHttpClient;
use App\Tests\Support\ConfiguresStripe;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StripeCheckoutControllerTest extends WebTestCase
{
    use ConfiguresStripe;

    protected function tearDown(): void
    {
        $this->restoreStripe();

        parent::tearDown();
    }

    public function testBuyCreatesACheckoutSessionAndSendsTheBuyerToStripe(): void
    {
        $this->enableStripe();
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/package/vendor/paid-plugin/buy');

        self::assertResponseRedirects('https://checkout.stripe.com/c/pay/cs_test_123');
    }

    public function testBuySendsADestinationChargeWithTheApplicationFee(): void
    {
        $this->enableStripe();
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/package/vendor/paid-plugin/buy');

        $params = $this->stripeRequestParams('/v1/checkout/sessions');

        // 9.99 at the default 10% fee: the vendor is paid the rest on their account.
        self::assertSame(100, $params['payment_intent_data']['application_fee_amount'] ?? null);
        self::assertSame('acct_vendor', $params['payment_intent_data']['transfer_data']['destination'] ?? null);
        self::assertSame('price_existing', $params['line_items'][0]['price'] ?? null);

        // The webhook has nothing but this metadata to tie a payment back to a buyer.
        self::assertSame('vendor/paid-plugin', $params['metadata']['package_name'] ?? null);
        self::assertSame('auth0|test123', $params['metadata']['auth0_user_id'] ?? null);
    }

    public function testBuySendsAnExistingOwnerBackToTheDetailPage(): void
    {
        $this->enableStripe();
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|buyer', 'Buyer', 'buyer@example.com', null), 'main');
        $client->request('GET', '/package/vendor/paid-plugin/buy');

        self::assertResponseRedirects('/package/vendor/paid-plugin');
        // No second charge may be started for a package the buyer already owns.
        self::assertSame(0, $this->stripeRequestCount('/v1/checkout/sessions'));
    }

    public function testBuyStopsWhenTheVendorCannotReceivePayouts(): void
    {
        // acct_pending finished onboarding but Stripe has not activated transfers, so a
        // destination charge would be refused. Fail with something the buyer can read
        // instead of letting the Stripe error surface.
        $this->enableStripe();
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/package/vendor/pending-plugin/buy');

        self::assertResponseRedirects('/package/vendor/pending-plugin');
        self::assertSame(0, $this->stripeRequestCount('/v1/checkout/sessions'));
    }

    public function testWebhookRevokesAccessOnAFullRefund(): void
    {
        $client = self::createClient();
        $this->postSignedWebhook($client, ['payment_intent' => 'pi_test_1', 'refunded' => true], 'charge.refunded');

        self::assertResponseIsSuccessful();
        $update = $this->lastStatusUpdate();
        self::assertStringContainsString('stripe_payment_intent_id=eq.pi_test_1', $update['url']);
        self::assertSame('refunded', $update['body']['status'] ?? null);
    }

    public function testWebhookKeepsAccessOnAPartialRefund(): void
    {
        // The buyer got some money back but still paid for the package.
        $client = self::createClient();
        $this->postSignedWebhook($client, ['payment_intent' => 'pi_test_1', 'refunded' => false], 'charge.refunded');

        self::assertResponseIsSuccessful();
        self::assertSame([], self::getContainer()->get(SupabaseMockHttpClient::class)->recordedStatusUpdates);
    }

    public function testWebhookRevokesAccessOnADispute(): void
    {
        $client = self::createClient();
        $this->postSignedWebhook($client, ['payment_intent' => 'pi_test_1'], 'charge.dispute.created');

        self::assertResponseIsSuccessful();
        self::assertSame('disputed', $this->lastStatusUpdate()['body']['status'] ?? null);
    }

    public function testWebhookRefreshesConnectAccountCapabilities(): void
    {
        $client = self::createClient();
        $this->postSignedWebhook($client, [
            'id' => 'acct_vendor',
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => true,
            'capabilities' => ['transfers' => 'inactive'],
        ], 'account.updated');

        self::assertResponseIsSuccessful();

        // A capability that lapses has to reach our copy, or the vendor keeps selling
        // packages whose checkout would fail.
        $update = $this->lastStatusUpdate();
        self::assertStringContainsString('stripe_account_id=eq.acct_vendor', $update['url']);
        self::assertFalse($update['body']['transfers_enabled'] ?? null);
        self::assertTrue($update['body']['details_submitted'] ?? null);
    }

    /**
     * @return array{url: string, body: array<string, mixed>}
     */
    private function lastStatusUpdate(): array
    {
        $updates = self::getContainer()->get(SupabaseMockHttpClient::class)->recordedStatusUpdates;
        self::assertNotSame([], $updates, 'No status update was written.');

        return $updates[array_key_last($updates)];
    }

    public function testBuyRejectsAFreePackage(): void
    {
        $this->enableStripe();
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/package/vendor/free-plugin/buy');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSuccessAndCancelBothReturnToTheDetailPage(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $client->request('GET', '/package/vendor/paid-plugin/purchase/success');
        self::assertResponseRedirects('/package/vendor/paid-plugin');

        $client->request('GET', '/package/vendor/paid-plugin/purchase/cancel');
        self::assertResponseRedirects('/package/vendor/paid-plugin');
    }

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

    public function testWebhookRecordsPurchaseForPaidSession(): void
    {
        $client = self::createClient();
        $this->postSignedWebhook($client, $this->checkoutSession('paid'));

        self::assertResponseIsSuccessful();

        $recorded = self::getContainer()->get(SupabaseMockHttpClient::class)->recordedPurchases;
        self::assertCount(1, $recorded);
        self::assertSame('auth0|buyer', $recorded[0]['auth0_user_id']);
        self::assertSame('mautic/zebra-theme', $recorded[0]['package_name']);
        self::assertSame('cs_test_1', $recorded[0]['stripe_checkout_session_id']);
        self::assertSame(9.99, $recorded[0]['amount']);
        self::assertSame('EUR', $recorded[0]['currency']);
    }

    public function testWebhookIgnoresSessionThatHasNotBeenPaid(): void
    {
        // Delayed-notification methods complete the session before the funds settle;
        // the package must stay locked until the payment actually goes through.
        $client = self::createClient();
        $this->postSignedWebhook($client, $this->checkoutSession('unpaid'));

        self::assertResponseIsSuccessful();
        self::assertSame([], self::getContainer()->get(SupabaseMockHttpClient::class)->recordedPurchases);
    }

    public function testWebhookIgnoresEventTypesOtherThanCompletedCheckout(): void
    {
        $client = self::createClient();
        $this->postSignedWebhook($client, $this->checkoutSession('paid'), 'payment_intent.succeeded');

        self::assertResponseIsSuccessful();
        self::assertSame([], self::getContainer()->get(SupabaseMockHttpClient::class)->recordedPurchases);
    }

    public function testWebhookIgnoresAPaidSessionWithoutMetadata(): void
    {
        // Without the package and buyer ids there is nothing to unlock, and guessing
        // would hand someone a package they did not pay for.
        $session = $this->checkoutSession('paid');
        unset($session['metadata']);

        $client = self::createClient();
        $this->postSignedWebhook($client, $session);

        self::assertResponseIsSuccessful();
        self::assertSame([], self::getContainer()->get(SupabaseMockHttpClient::class)->recordedPurchases);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutSession(string $paymentStatus): array
    {
        return [
            'id' => 'cs_test_1',
            'payment_status' => $paymentStatus,
            'payment_intent' => 'pi_test_1',
            'amount_total' => 999,
            'currency' => 'eur',
            'metadata' => [
                'package_name' => 'mautic/zebra-theme',
                'auth0_user_id' => 'auth0|buyer',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $session
     */
    private function postSignedWebhook(KernelBrowser $client, array $session, string $type = 'checkout.session.completed'): void
    {
        $payload = (string) json_encode([
            'type' => $type,
            'data' => ['object' => $session],
        ]);

        // Same scheme Stripe uses: HMAC-SHA256 over "<timestamp>.<payload>".
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        $client->request(
            'POST',
            '/stripe/webhook',
            server: ['HTTP_Stripe-Signature' => 't='.$timestamp.',v1='.$signature],
            content: $payload,
        );
    }
}
