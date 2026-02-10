<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth0\Exception\Auth0AuthenticationException;
use App\Auth0\Validator\Auth0TokenValidator;
use App\Marketplace\Dto\ReviewRequest;
use App\Marketplace\Exception\MarketplaceApiException;
use App\Marketplace\Exception\ReviewValidationException;
use App\Marketplace\MarketplaceApiClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReviewApiController extends AbstractController
{
    public function __construct(
        private readonly MarketplaceApiClient $apiClient,
        private readonly Auth0TokenValidator $tokenValidator,
    ) {
    }

    public function submit(Request $request, string $package): JsonResponse
    {
        $authHeader = $request->headers->get('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return $this->json(['error' => 'Missing or invalid Authorization header.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $userInfo = $this->tokenValidator->validate(substr($authHeader, 7));
        } catch (Auth0AuthenticationException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $reviewRequest = ReviewRequest::fromPayload($request->getPayload());
        } catch (ReviewValidationException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->apiClient->submitReview(
                $package,
                $userInfo['sub'],
                $userInfo['name'] ?? $userInfo['email'] ?? 'Anonymous',
                $userInfo['picture'] ?? null,
                $reviewRequest,
            );
        } catch (MarketplaceApiException $e) {
            return $this->json(['error' => 'Failed to submit review.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([], Response::HTTP_CREATED);
    }
}
