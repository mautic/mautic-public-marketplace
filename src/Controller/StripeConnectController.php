<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth0\Auth0User;
use App\Marketplace\MarketplaceApiClient;
use App\Stripe\Exception\StripeConnectException;
use App\Stripe\StripeConnectClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Vendor onboarding onto Stripe Connect. Onboarding itself is Stripe-hosted (Account
 * Links), so Stripe handles identity, tax and bank details; we only persist the
 * connected-account id and its capability flags.
 */
final class StripeConnectController extends AbstractController
{
    public function __construct(
        private readonly StripeConnectClient $stripe,
        private readonly MarketplaceApiClient $apiClient,
    ) {
    }

    public function onboard(): Response
    {
        $user = $this->requireUser();

        if (!$this->stripe->isConfigured()) {
            $this->addFlash('error', 'Stripe is not configured yet. Please try again later.');

            return $this->redirectToRoute('profile_page');
        }

        $existing = $this->apiClient->getStripeConnectAccount($user->getUserIdentifier());
        $accountId = $existing['stripe_account_id'] ?? null;

        try {
            if (null === $accountId) {
                $accountId = $this->stripe->createConnectedAccount((string) $user->getEmail());
                $this->apiClient->saveStripeConnectAccount($user->getUserIdentifier(), $accountId, false, false, false);
            }

            $onboardingUrl = $this->stripe->createOnboardingLink(
                $accountId,
                $this->generateUrl('marketplace_stripe_connect_return', [], UrlGeneratorInterface::ABSOLUTE_URL),
                $this->generateUrl('marketplace_stripe_connect_refresh', [], UrlGeneratorInterface::ABSOLUTE_URL),
            );
        } catch (StripeConnectException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('profile_page');
        }

        return $this->redirect($onboardingUrl);
    }

    public function onboardingReturn(): Response
    {
        $user = $this->requireUser();
        $existing = $this->apiClient->getStripeConnectAccount($user->getUserIdentifier());

        if (null === $existing) {
            return $this->redirectToRoute('profile_page');
        }

        try {
            $status = $this->stripe->retrieveAccount($existing['stripe_account_id']);
            $this->apiClient->saveStripeConnectAccount(
                $user->getUserIdentifier(),
                $status['id'],
                $status['charges_enabled'],
                $status['payouts_enabled'],
                $status['details_submitted'],
                $status['transfers_enabled'],
            );

            // Stripe can still be reviewing the transfers capability after the form is
            // submitted, and without it no payment can be split to this vendor.
            if (!$status['details_submitted']) {
                $this->addFlash('info', 'Stripe onboarding is not finished yet — you can resume it any time.');
            } elseif (!$status['transfers_enabled']) {
                $this->addFlash('info', 'Stripe is still reviewing your account. You can publish paid packages once it can receive payouts.');
            } else {
                $this->addFlash('success', 'Your Stripe account is connected. You can now publish paid packages.');
            }
        } catch (StripeConnectException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('profile_page');
    }

    public function refresh(): Response
    {
        // Account Links are single-use and expire; send the vendor back through onboarding.
        return $this->redirectToRoute('marketplace_stripe_connect_onboard');
    }

    private function requireUser(): Auth0User
    {
        $user = $this->getUser();
        if (!$user instanceof Auth0User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
