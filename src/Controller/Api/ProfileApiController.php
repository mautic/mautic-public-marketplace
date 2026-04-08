<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth0\Exception\Auth0AuthenticationException;
use App\Marketplace\MarketplaceApiClient;
use App\Supabase\Exception\SupabaseApiException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ProfileApiController extends AbstractController
{
    public function __construct(
        private readonly MarketplaceApiClient $apiClient,
    ) {
    }

    public function profile(Request $request): JsonResponse
    {
        $authHeader = $request->headers->get('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return $this->json(['error' => 'Missing or invalid Authorization header.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $userInfo = $this->apiClient->validateToken(substr($authHeader, 7));
        } catch (Auth0AuthenticationException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $reviews = $this->apiClient->getUserReviews($userInfo['sub']);
        } catch (SupabaseApiException) {
            $reviews = [];
        }

        $userName = $userInfo['name'] ?? $userInfo['email'] ?? null;
        $userEmail = $userInfo['email'] ?? null;

        try {
            $packages = (null !== $userName && '' !== $userName)
                ? $this->apiClient->getUserPackages($userName, $userEmail)
                : [];
        } catch (SupabaseApiException) {
            $packages = [];
        }

        return $this->json([
            'user' => [
                'sub' => $userInfo['sub'],
                'name' => $userInfo['name'] ?? null,
                'email' => $userInfo['email'] ?? null,
                'picture' => $userInfo['picture'] ?? null,
            ],
            'reviews' => $reviews,
            'uploaded_packages' => $packages,
        ]);
    }
}
