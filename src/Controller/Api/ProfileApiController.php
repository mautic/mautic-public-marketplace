<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth0\Auth0User;
use App\Marketplace\MarketplaceApiClient;
use App\Supabase\Exception\SupabaseApiException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

final class ProfileApiController extends AbstractController
{
    public function __construct(
        private readonly MarketplaceApiClient $apiClient,
    ) {
    }

    public function profile(): Response
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

        return $this->render('profile/_content.html.twig', [
            'user' => $user,
            'reviews' => $reviews,
            'uploaded_packages' => $uploadedPackages,
        ]);
    }
}
