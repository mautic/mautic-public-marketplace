<?php

declare(strict_types=1);

namespace App\Tests\Stripe;

use App\Stripe\Exception\StripeConnectException;
use App\Stripe\StripeConnectClient;
use App\Tests\Mock\StripeMockHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;

final class StripeConnectClientTest extends TestCase
{
    protected function tearDown(): void
    {
        // Restore the real HTTP client so the global override doesn't leak to other tests.
        ApiRequestor::setHttpClient(new CurlClient());

        parent::tearDown();
    }

    private function client(StripeMockHttpClient $http, string $secretKey = 'sk_test_x', string $webhookSecret = 'whsec_test'): StripeConnectClient
    {
        // Stripe uses a global HTTP client; point it at our recording mock.
        ApiRequestor::setHttpClient($http);

        return new StripeConnectClient($secretKey, $webhookSecret, new NullLogger());
    }

    public function testIsConfiguredReflectsSecretKey(): void
    {
        self::assertTrue($this->client(new StripeMockHttpClient())->isConfigured());
        self::assertFalse($this->client(new StripeMockHttpClient(), '')->isConfigured());
    }

    public function testCreateConnectedAccountReturnsAccountId(): void
    {
        $http = new StripeMockHttpClient();
        $id = $this->client($http)->createConnectedAccount('vendor@example.com');

        self::assertSame('acct_test_123', $id);
        self::assertStringContainsString('/v1/accounts', $http->requests[0]['url']);
        self::assertSame('express', $http->requests[0]['params']['type'] ?? null);
    }

    public function testCreateOnboardingLinkReturnsHostedUrl(): void
    {
        $http = new StripeMockHttpClient();
        $url = $this->client($http)->createOnboardingLink('acct_1', 'https://app.test/return', 'https://app.test/refresh');

        self::assertSame('https://connect.stripe.com/setup/s/test', $url);
        self::assertStringContainsString('/v1/account_links', $http->requests[0]['url']);
        self::assertSame('account_onboarding', $http->requests[0]['params']['type'] ?? null);
    }

    public function testCreateProductWithPriceReturnsIds(): void
    {
        $http = new StripeMockHttpClient();
        $refs = $this->client($http)->createProductWithPrice('My Package', 'Nice package', 999, 'EUR');

        self::assertSame('prod_test_123', $refs['product_id']);
        self::assertSame('price_test_123', $refs['price_id']);
        // Price is created in the lowest denomination with a lowercased currency.
        self::assertSame(999, $http->requests[1]['params']['unit_amount'] ?? null);
        self::assertSame('eur', $http->requests[1]['params']['currency'] ?? null);
    }

    public function testCreateCheckoutSessionUsesDestinationChargeWithApplicationFee(): void
    {
        $http = new StripeMockHttpClient();
        $session = $this->client($http)->createCheckoutSession('price_1', 'acct_vendor', 100, 'https://app.test/ok', 'https://app.test/cancel');

        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_123', $session['url']);
        $params = $http->requests[0]['params'];
        self::assertSame(100, $params['payment_intent_data']['application_fee_amount'] ?? null);
        self::assertSame('acct_vendor', $params['payment_intent_data']['transfer_data']['destination'] ?? null);
    }

    public function testConstructWebhookEventParsesValidSignature(): void
    {
        $secret = 'whsec_test';
        $payload = (string) json_encode([
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_1', 'metadata' => ['package_name' => 'a/b']]],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        $event = $this->client(new StripeMockHttpClient(), 'sk_test_x', $secret)
            ->constructWebhookEvent($payload, 't='.$timestamp.',v1='.$signature);

        self::assertSame('checkout.session.completed', $event['type']);
        self::assertSame('cs_1', $event['object']['id'] ?? null);
    }

    public function testConstructWebhookEventRejectsInvalidSignature(): void
    {
        $this->expectException(StripeConnectException::class);

        $this->client(new StripeMockHttpClient(), 'sk_test_x', 'whsec_test')
            ->constructWebhookEvent('{}', 't=1,v1=deadbeef');
    }
}
