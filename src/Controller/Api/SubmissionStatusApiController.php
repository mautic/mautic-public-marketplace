<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth0\Auth0User;
use App\Marketplace\MarketplaceApiClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SubmissionStatusApiController extends AbstractController
{
    public function __construct(
        private readonly MarketplaceApiClient $apiClient,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    public function status(string $package): JsonResponse
    {
        $row = $this->apiClient->getPackageByName($package);
        if (null === $row) {
            return $this->json(['error' => 'Package not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var Auth0User $user */
        $user = $this->getUser();
        if (($row['auth0_user_id'] ?? null) !== $user->getUserIdentifier()) {
            return $this->json(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        $status = $row['status'] ?? 'pending';
        if (!\is_string($status) || '' === $status) {
            $status = 'pending';
        }

        return $this->json([
            'package' => $package,
            'status' => $status,
            'updated_at' => $row['time'] ?? $row['created_at'] ?? null,
        ]);
    }
}
