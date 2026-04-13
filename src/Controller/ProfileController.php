<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth0\Auth0User;
use App\Marketplace\MarketplaceApiClient;
use App\Supabase\Exception\SupabaseApiException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly MarketplaceApiClient $apiClient,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        /** @var Auth0User $user */
        $user = $this->getUser();

        try {
            $reviews = $this->apiClient->getUserReviews($user->getUserIdentifier());
        } catch (SupabaseApiException) {
            $reviews = [];
        }

        try {
            $uploadedPackages = $this->apiClient->getUserUploadedPackages($user->getUserIdentifier());
        } catch (SupabaseApiException) {
            $uploadedPackages = [];
        }

        return $this->render('profile/index.html.twig', [
            'reviews' => $reviews,
            'uploaded_packages' => $uploadedPackages,
        ]);
    }
}
