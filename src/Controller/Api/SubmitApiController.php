<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth0\Auth0User;
use App\Marketplace\Dto\SubmitRequest;
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
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SubmitApiController extends AbstractController
{
    public function __construct(
        private readonly PackageSubmitService $submitService,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    public function submit(Request $request): JsonResponse
    {
        $zip = $request->files->get('zip');
        if (!$zip instanceof UploadedFile) {
            return $this->json(['error' => 'A ZIP file is required.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$zip->isValid()) {
            return $this->json(['error' => \sprintf('Upload failed: %s', $zip->getErrorMessage())], Response::HTTP_BAD_REQUEST);
        }
        if ($zip->getSize() > PackageZipUploader::MAX_BYTES) {
            return $this->json(
                ['error' => \sprintf('The ZIP file is too large. Maximum size is %dMB.', PackageZipUploader::MAX_BYTES / 1024 / 1024)],
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }

        $submitRequest = $this->buildSubmitRequest($request);
        $violations = $this->validator->validate($submitRequest);

        if ($violations->count() > 0) {
            return $this->json([
                'error' => 'Validation failed.',
                'violations' => $this->formatViolations($violations),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $banner = $request->files->get('banner');
        if (null !== $banner && !$banner instanceof UploadedFile) {
            $banner = null;
        }

        $galleryFiles = [];
        $galleryRaw = $request->files->get('gallery');
        if (\is_array($galleryRaw)) {
            foreach ($galleryRaw as $file) {
                if ($file instanceof UploadedFile) {
                    $galleryFiles[] = $file;
                }
            }
        }

        /** @var Auth0User $user */
        $user = $this->getUser();

        try {
            $result = $this->submitService->submit(
                $zip->getPathname(),
                $submitRequest,
                $user,
                $banner,
                $galleryFiles,
            );
        } catch (SubmitValidationException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (SupabaseApiException $e) {
            // The detail (HTTP status, storage/PostgREST message) is logged but kept out of the
            // client response so we don't leak backend internals. The reference ties the user's
            // report to this exact log entry so support can see what actually failed.
            $reference = $this->errorReference();
            $this->logger->error('Package publish failed: Supabase error.', ['reference' => $reference, 'exception' => $e]);

            return $this->json([
                'error' => \sprintf('We could not publish your package because a backend service did not respond as expected. This is usually temporary — please try again in a moment. If it keeps happening, contact support and quote reference %s.', $reference),
                'reference' => $reference,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $e) {
            // Last-resort guard: anything unexpected still returns JSON the wizard can render
            // instead of an opaque HTML 500 that surfaces as the generic "something went wrong".
            $reference = $this->errorReference();
            $this->logger->error('Package publish failed: unexpected error.', ['reference' => $reference, 'exception' => $e]);

            return $this->json([
                'error' => \sprintf('Something unexpected went wrong while publishing your package. Please try again. If it keeps happening, contact support and quote reference %s.', $reference),
                'reference' => $reference,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($result, Response::HTTP_CREATED);
    }

    /**
     * Short code shown to the user and attached to the server-side log so a failed publish
     * can be traced to its exact stack trace without exposing backend internals to the client.
     */
    private function errorReference(): string
    {
        return strtoupper(bin2hex(random_bytes(3)));
    }

    private function buildSubmitRequest(Request $request): SubmitRequest
    {
        $bag = $request->request;

        return new SubmitRequest(
            name: (string) $bag->get('name', ''),
            version: (string) $bag->get('version', ''),
            category: (string) $bag->get('category', ''),
            headline: (string) $bag->get('headline', ''),
            keywords: $this->stringList($bag->all()['keywords'] ?? []),
            description: (string) $bag->get('description', ''),
            languages: $this->stringList($bag->all()['languages'] ?? []),
            works_with: $this->stringList($bag->all()['works_with'] ?? []),
            license_type: (string) $bag->get('license_type', ''),
            pricing_model: (string) $bag->get('pricing_model', 'free'),
            price: (float) $bag->get('price', 0),
            currency: $this->nullIfBlank($bag->get('currency')),
            ip_ownership_accepted: filter_var($bag->get('ip_ownership_accepted', false), \FILTER_VALIDATE_BOOLEAN),
            github_url: $this->nullIfBlank($bag->get('github_url')),
            packagist_url: $this->nullIfBlank($bag->get('packagist_url')),
            documentation: $this->nullIfBlank($bag->get('documentation')),
            gallery_alt: $this->stringList($bag->all()['gallery_alt'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (\is_string($value)) {
            $decoded = json_decode($value, true);
            $value = \is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $value)));
        }

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($v): string => \is_scalar($v) ? trim((string) $v) : '', $value),
            static fn (string $v): bool => '' !== $v,
        ));
    }

    private function nullIfBlank(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private function formatViolations(ConstraintViolationListInterface $violations): array
    {
        $out = [];
        foreach ($violations as $violation) {
            $out[] = [
                'field' => $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return $out;
    }
}
