<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth0\Auth0User;
use App\Marketplace\MarketplaceApiClient;
use App\Stripe\Exception\StripeConnectException;
use App\Stripe\StripeConnectClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Buying a paid package via Stripe Checkout. Uses a destination charge so the vendor is
 * paid their split automatically, minus the platform application fee. Access is granted
 * once the webhook confirms the payment.
 */
final class StripeCheckoutController extends AbstractController
{
    public function __construct(
        private readonly StripeConnectClient $stripe,
        private readonly MarketplaceApiClient $apiClient,
        #[Autowire(env: 'int:STRIPE_APPLICATION_FEE_PERCENT')]
        private readonly int $applicationFeePercent,
    ) {
    }

    public function buy(string $package): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Auth0User) {
            throw $this->createAccessDeniedException();
        }

        $checkout = $this->apiClient->getPackageCheckoutData($package);
        if (null === $checkout || 'paid' !== $checkout['pricing_model']) {
            throw $this->createNotFoundException(\sprintf('Paid package "%s" not found.', $package));
        }

        if ($this->apiClient->hasPurchased($user->getUserIdentifier(), $package)) {
            $this->addFlash('info', 'You already own this package.');

            return $this->redirectToRoute('marketplace_detail', ['package' => $package]);
        }

        if (!$this->stripe->isConfigured() || null === $checkout['stripe_price_id'] || null === $checkout['vendor_stripe_account_id']) {
            $this->addFlash('error', 'This package is not available for purchase yet.');

            return $this->redirectToRoute('marketplace_detail', ['package' => $package]);
        }

        // A vendor's transfers capability can lapse after publishing (Stripe reviews
        // accounts on an ongoing basis). Checking it here turns what would otherwise be
        // an opaque Stripe error at session creation into something the buyer can read.
        $vendor = $this->apiClient->getStripeConnectAccountByAccountId($checkout['vendor_stripe_account_id']);
        if (null === $vendor || !$vendor['transfers_enabled']) {
            $this->addFlash('error', 'This package cannot be bought right now because its author cannot receive payments.');

            return $this->redirectToRoute('marketplace_detail', ['package' => $package]);
        }

        $amountInCents = (int) round(($checkout['price'] ?? 0.0) * 100);
        $applicationFee = (int) round($amountInCents * $this->applicationFeePercent / 100);

        try {
            $session = $this->stripe->createCheckoutSession(
                $checkout['stripe_price_id'],
                $checkout['vendor_stripe_account_id'],
                $applicationFee,
                $this->generateUrl('marketplace_stripe_checkout_success', ['package' => $package], UrlGeneratorInterface::ABSOLUTE_URL),
                $this->generateUrl('marketplace_stripe_checkout_cancel', ['package' => $package], UrlGeneratorInterface::ABSOLUTE_URL),
                ['package_name' => $package, 'auth0_user_id' => $user->getUserIdentifier()],
            );
        } catch (StripeConnectException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('marketplace_detail', ['package' => $package]);
        }

        return $this->redirect($session['url']);
    }

    public function success(string $package): Response
    {
        // The webhook is the source of truth for access; this is just user-facing feedback.
        $this->addFlash('success', 'Thanks for your purchase! Your access is being confirmed.');

        return $this->redirectToRoute('marketplace_detail', ['package' => $package]);
    }

    public function cancel(string $package): Response
    {
        $this->addFlash('info', 'Checkout was cancelled — you have not been charged.');

        return $this->redirectToRoute('marketplace_detail', ['package' => $package]);
    }

    public function webhook(Request $request): Response
    {
        try {
            $event = $this->stripe->constructWebhookEvent(
                $request->getContent(),
                $request->headers->get('Stripe-Signature', ''),
            );
        } catch (StripeConnectException) {
            return new Response('Invalid signature', Response::HTTP_BAD_REQUEST);
        }

        match ($event['type']) {
            'checkout.session.completed' => $this->recordCompletedPurchase($event['object']),
            // Money going back means the buyer must stop having the package.
            'charge.refunded' => $this->revokePurchase($event['object'], 'refunded'),
            'charge.dispute.created' => $this->revokePurchase($event['object'], 'disputed'),
            'account.updated' => $this->refreshConnectAccount($event['object']),
            default => null,
        };

        return new Response('ok', Response::HTTP_OK);
    }

    /**
     * A refund or dispute carries no metadata of ours, but it does carry the payment
     * intent the purchase was recorded with.
     *
     * @param array<string, mixed> $object
     */
    private function revokePurchase(array $object, string $status): void
    {
        // On a partial refund the buyer keeps what they paid for, so only a fully
        // refunded charge takes the package away.
        if ('refunded' === $status && true !== ($object['refunded'] ?? null)) {
            return;
        }

        $paymentIntent = $object['payment_intent'] ?? null;
        if (!\is_string($paymentIntent) || '' === $paymentIntent) {
            return;
        }

        $this->apiClient->revokePurchaseByPaymentIntent($paymentIntent, $status);
    }

    /**
     * @param array<string, mixed> $account
     */
    private function refreshConnectAccount(array $account): void
    {
        $accountId = $account['id'] ?? null;
        if (!\is_string($accountId) || '' === $accountId) {
            return;
        }

        $status = StripeConnectClient::accountStatus($account, $accountId);

        $this->apiClient->updateStripeConnectAccountStatus(
            $accountId,
            $status['charges_enabled'],
            $status['payouts_enabled'],
            $status['details_submitted'],
            $status['transfers_enabled'],
        );
    }

    /**
     * @param array<string, mixed> $session
     */
    private function recordCompletedPurchase(array $session): void
    {
        // A session can complete before the money arrives — delayed-notification methods
        // (SEPA debit, bank transfer) leave it unpaid or processing — so completion alone
        // must not unlock the package.
        if ('paid' !== ($session['payment_status'] ?? null)) {
            return;
        }

        $metadata = \is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $packageName = (string) ($metadata['package_name'] ?? '');
        $userId = (string) ($metadata['auth0_user_id'] ?? '');
        $sessionId = (string) ($session['id'] ?? '');

        if ('' === $packageName || '' === $userId || '' === $sessionId) {
            return;
        }

        $this->apiClient->recordPurchase(
            $userId,
            $packageName,
            $sessionId,
            isset($session['payment_intent']) ? (string) $session['payment_intent'] : null,
            isset($session['amount_total']) ? ((float) $session['amount_total']) / 100 : null,
            isset($session['currency']) ? strtoupper((string) $session['currency']) : null,
        );
    }
}
