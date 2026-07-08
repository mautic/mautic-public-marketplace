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

        if ('checkout.session.completed' === $event['type']) {
            $this->recordCompletedPurchase($event['object']);
        }

        return new Response('ok', Response::HTTP_OK);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function recordCompletedPurchase(array $session): void
    {
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
