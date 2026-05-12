<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth0\Auth0User;
use App\Marketplace\Dto\SubmitRequest;
use App\Marketplace\Exception\SubmitValidationException;
use App\Marketplace\PackageSubmitService;
use App\Supabase\Exception\SupabaseApiException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SubmitApiController extends AbstractController
{
    public function __construct(
        private readonly PackageSubmitService $submitService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    public function submit(#[MapRequestPayload] SubmitRequest $submitRequest): JsonResponse
    {
        /** @var Auth0User $user */
        $user = $this->getUser();

        try {
            $result = $this->submitService->submit($submitRequest->asset_url, $user);
        } catch (SubmitValidationException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (SupabaseApiException $e) {
            $this->logger->error('Package submit failed at marketplace storage layer.', [
                'asset_url' => $submitRequest->asset_url,
                'exception' => $e,
            ]);

            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json($result, Response::HTTP_CREATED);
    }
}
