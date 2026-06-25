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

        // Mautic's campaign-share flow packs the wizard's metadata into extra.mautic.*
        // (the same fields PackageSubmitService reads for direct uploads), so surface
        // them here too — otherwise the wizard re-asks for headline/languages/etc. the
        // archive already carries.
        $mauticExtra = \is_array($data['extra']['mautic'] ?? null) ? $data['extra']['mautic'] : [];
        $languages = \is_array($mauticExtra['languages'] ?? null)
            ? array_values(array_filter($mauticExtra['languages'], 'is_string'))
            : [];
        $worksWith = \is_array($mauticExtra['works-with'] ?? null)
            ? array_values(array_filter($mauticExtra['works-with'], 'is_string'))
            : [];
        $priceAmount = $mauticExtra['price']['amount'] ?? null;

        return $this->json([
            'name' => $data['name'],
            'version' => $data['version'],
            'type' => $data['type'],
            'headline' => isset($mauticExtra['headline']) ? (string) $mauticExtra['headline'] : null,
            'description' => $data['description'] ?? null,
            'keywords' => $keywords,
            'languages' => $languages,
            'works_with' => $worksWith,
            'price' => is_numeric($priceAmount) ? (float) $priceAmount : null,
            'license' => $license,
            'authors' => $authors,
            'require' => $require,
            'mautic_version_constraint' => $this->composerReader->extractMauticVersion($data),
        ]);
    }
}
