<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth0\Auth0User;
use App\Marketplace\Exception\SubmitValidationException;
use App\Marketplace\PackageSubmitService;
use App\Marketplace\PackageZipUploader;
use App\Supabase\Exception\SupabaseApiException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UploadApiController extends AbstractController
{
    public function __construct(
        private readonly PackageSubmitService $submitService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    public function upload(Request $request): JsonResponse
    {
        /** @var Auth0User $user */
        $user = $this->getUser();

        $file = $request->files->get('package');

        if (!$file instanceof UploadedFile) {
            return $this->json(
                ['error' => 'No package file uploaded. Expected a multipart/form-data field named "package".'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (!$file->isValid()) {
            return $this->json(
                ['error' => \sprintf('Upload failed: %s', $file->getErrorMessage())],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($file->getSize() > PackageZipUploader::MAX_BYTES) {
            return $this->json(
                ['error' => \sprintf('The ZIP file is too large. Maximum size is %dMB.', PackageZipUploader::MAX_BYTES / 1024 / 1024)],
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }

        try {
            $result = $this->submitService->submitFromFile($file->getPathname(), $user);
        } catch (SubmitValidationException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (SupabaseApiException $e) {
            $this->logger->error('Package upload failed at marketplace storage layer.', [
                'filename' => $file->getClientOriginalName(),
                'exception' => $e,
            ]);

            return $this->json(
                ['error' => "We couldn't save the package right now. Please try again in a moment, or contact support if it keeps happening."],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        return $this->json($result, Response::HTTP_CREATED);
    }
}
