<?php

declare(strict_types=1);

namespace App\Stripe;

use App\Stripe\Exception\StripeConnectException;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Thin wrapper over the Stripe SDK for the Connect (split-payment) flows. Vendors
 * onboard through Stripe-hosted Account Links so Stripe handles KYC, tax and bank
 * details; we only keep the resulting connected-account id.
 */
final class StripeConnectClient
{
    private ?StripeClient $stripe = null;

    public function __construct(
        #[Autowire(env: 'STRIPE_SECRET_KEY')]
        private readonly string $secretKey,
        #[Autowire(env: 'STRIPE_WEBHOOK_SECRET')]
        private readonly string $webhookSecret,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->secretKey;
    }

    /**
     * Creates an Express connected account for a vendor and returns its id.
     *
     * @throws StripeConnectException
     */
    public function createConnectedAccount(string $email): string
    {
        try {
            $account = $this->client()->accounts->create([
                'type' => 'express',
                'email' => $email,
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
            ]);
        } catch (ApiErrorException $exception) {
            $this->logger->error('Stripe connected account creation failed.', ['error' => $exception->getMessage()]);

            throw new StripeConnectException('Could not create the Stripe account.', previous: $exception);
        }

        return (string) $account->toArray()['id'];
    }

    /**
     * Returns a one-time Stripe-hosted onboarding URL for the connected account.
     *
     * @throws StripeConnectException
     */
    public function createOnboardingLink(string $accountId, string $returnUrl, string $refreshUrl): string
    {
        try {
            $link = $this->client()->accountLinks->create([
                'account' => $accountId,
                'return_url' => $returnUrl,
                'refresh_url' => $refreshUrl,
                'type' => 'account_onboarding',
            ]);
        } catch (ApiErrorException $exception) {
            $this->logger->error('Stripe account link creation failed.', ['error' => $exception->getMessage()]);

            throw new StripeConnectException('Could not start Stripe onboarding.', previous: $exception);
        }

        return (string) $link->toArray()['url'];
    }

    /**
     * Reads the onboarding/capability status of a connected account.
     *
     * @return array{id: string, charges_enabled: bool, payouts_enabled: bool, details_submitted: bool}
     *
     * @throws StripeConnectException
     */
    public function retrieveAccount(string $accountId): array
    {
        try {
            $account = $this->client()->accounts->retrieve($accountId);
        } catch (ApiErrorException $exception) {
            $this->logger->error('Stripe account retrieval failed.', ['error' => $exception->getMessage()]);

            throw new StripeConnectException('Could not read the Stripe account.', previous: $exception);
        }

        $data = $account->toArray();

        return [
            'id' => (string) ($data['id'] ?? $accountId),
            'charges_enabled' => (bool) ($data['charges_enabled'] ?? false),
            'payouts_enabled' => (bool) ($data['payouts_enabled'] ?? false),
            'details_submitted' => (bool) ($data['details_submitted'] ?? false),
        ];
    }

    /**
     * Creates a Stripe product and a one-time price for a paid package on the platform
     * account. The split to the vendor happens at checkout via the connected account.
     *
     * @return array{product_id: string, price_id: string}
     *
     * @throws StripeConnectException
     */
    public function createProductWithPrice(string $name, ?string $description, int $amountInCents, string $currency): array
    {
        try {
            $product = $this->client()->products->create(array_filter([
                'name' => $name,
                'description' => null !== $description && '' !== $description ? $description : null,
            ]));

            $price = $this->client()->prices->create([
                'product' => (string) $product->toArray()['id'],
                'unit_amount' => $amountInCents,
                'currency' => strtolower($currency),
            ]);
        } catch (ApiErrorException $exception) {
            $this->logger->error('Stripe product/price creation failed.', ['error' => $exception->getMessage()]);

            throw new StripeConnectException('Could not create the Stripe product for this package.', previous: $exception);
        }

        return [
            'product_id' => (string) $product->toArray()['id'],
            'price_id' => (string) $price->toArray()['id'],
        ];
    }

    /**
     * Creates a Stripe-hosted Checkout session for a paid package. Uses a destination
     * charge (transfer_data.destination) so the vendor is paid their split automatically,
     * minus the platform application fee.
     *
     * @param array<string, string> $metadata
     *
     * @return array{id: string, url: string}
     *
     * @throws StripeConnectException
     */
    public function createCheckoutSession(
        string $priceId,
        string $destinationAccountId,
        int $applicationFeeAmount,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
    ): array {
        try {
            $session = $this->client()->checkout->sessions->create([
                'mode' => 'payment',
                'line_items' => [['price' => $priceId, 'quantity' => 1]],
                'payment_intent_data' => [
                    'application_fee_amount' => $applicationFeeAmount,
                    'transfer_data' => ['destination' => $destinationAccountId],
                ],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $metadata,
            ]);
        } catch (ApiErrorException $exception) {
            $this->logger->error('Stripe checkout session creation failed.', ['error' => $exception->getMessage()]);

            throw new StripeConnectException('Could not start checkout for this package.', previous: $exception);
        }

        $data = $session->toArray();

        return [
            'id' => (string) $data['id'],
            'url' => (string) ($data['url'] ?? ''),
        ];
    }

    /**
     * Verifies a Stripe webhook signature and returns the event type and object payload.
     *
     * @return array{type: string, object: array<string, mixed>}
     *
     * @throws StripeConnectException
     */
    public function constructWebhookEvent(string $payload, string $signatureHeader): array
    {
        if ('' === $this->webhookSecret) {
            throw new StripeConnectException('Stripe webhook secret is not configured.');
        }

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $signatureHeader, $this->webhookSecret);
        } catch (\UnexpectedValueException|\Stripe\Exception\SignatureVerificationException $exception) {
            throw new StripeConnectException('Invalid Stripe webhook signature.', previous: $exception);
        }

        $data = $event->toArray();
        $object = $data['data']['object'] ?? [];

        return [
            'type' => (string) ($data['type'] ?? ''),
            'object' => \is_array($object) ? $object : [],
        ];
    }

    private function client(): StripeClient
    {
        if (!$this->isConfigured()) {
            throw new StripeConnectException('Stripe is not configured (STRIPE_SECRET_KEY is empty).');
        }

        return $this->stripe ??= new StripeClient($this->secretKey);
    }
}
