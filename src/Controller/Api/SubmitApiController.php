<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth0\Exception\Auth0AuthenticationException;
use App\Marketplace\Dto\SubmitRequest;
use App\Marketplace\Exception\SubmitValidationException;
use App\Marketplace\MarketplaceApiClient;
use App\Marketplace\PackageSubmitService;
use App\Supabase\Exception\SupabaseApiException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;

final class SubmitApiController extends AbstractController
{
    public function __construct(
        private readonly MarketplaceApiClient $apiClient,
        private readonly PackageSubmitService $submitService,
    ) {
    }

    public function submit(Request $request, #[MapRequestPayload] SubmitRequest $submitRequest): JsonResponse
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
            $result = $this->submitService->submit($submitRequest->asset_url, $userInfo);
        } catch (SubmitValidationException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (SupabaseApiException $e) {
            return $this->json(['error' => 'Failed to publish package.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($result, Response::HTTP_CREATED);
    }
}
