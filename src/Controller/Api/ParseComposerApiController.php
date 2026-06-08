<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Marketplace\ComposerJsonReader;
use App\Marketplace\Exception\SubmitValidationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ParseComposerApiController extends AbstractController
{
    public function __construct(
        private readonly ComposerJsonReader $composerReader,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    public function parse(Request $request): JsonResponse
    {
        $zip = $request->files->get('zip');
        if (!$zip instanceof UploadedFile) {
            return $this->json(['error' => 'A ZIP file is required.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$zip->isValid()) {
            return $this->json(['error' => \sprintf('Upload failed: %s', $zip->getErrorMessage())], Response::HTTP_BAD_REQUEST);
        }

        try {
            $data = $this->composerReader->read($zip->getPathname());
            $this->composerReader->validate($data);
        } catch (SubmitValidationException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $require = \is_array($data['require'] ?? null) ? $data['require'] : [];
        $authors = \is_array($data['authors'] ?? null) ? $data['authors'] : [];
        $keywords = \is_array($data['keywords'] ?? null)
            ? array_values(array_filter($data['keywords'], 'is_string'))
            : [];
        $license = $data['license'] ?? null;

        return $this->json([
            'name' => $data['name'],
            'version' => $data['version'],
            'type' => $data['type'],
            'description' => $data['description'] ?? null,
            'keywords' => $keywords,
            'license' => $license,
            'authors' => $authors,
            'require' => $require,
            'mautic_version_constraint' => $this->composerReader->extractMauticVersion($data),
        ]);
    }
}
