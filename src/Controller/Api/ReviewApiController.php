<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth0\Auth0User;
use App\Marketplace\Dto\ReviewRequest;
use App\Marketplace\Exception\ReviewValidationException;
use App\Marketplace\MarketplaceApiClient;
use App\Supabase\Exception\SupabaseApiException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReviewApiController extends AbstractController
{
    public function __construct(
        private readonly MarketplaceApiClient $apiClient,
    ) {
    }

    public function submit(Request $request, string $package): JsonResponse
    {
        /** @var Auth0User $user */
        $user = $this->getUser();

        try {
            $reviewRequest = ReviewRequest::fromPayload($request->getPayload());
        } catch (ReviewValidationException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->apiClient->submitReview(
                $package,
                $user->getUserIdentifier(),
                $user->getName() ?? $user->getEmail() ?? 'Anonymous',
                $user->getPicture(),
                $reviewRequest,
            );
        } catch (SupabaseApiException) {
            return $this->json(['error' => 'Failed to submit review.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([], Response::HTTP_CREATED);
    }
}
