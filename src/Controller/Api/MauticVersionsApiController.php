<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

final class MauticVersionsApiController extends AbstractController
{
    // TODO(predrag.vukovic@mautic.org): replace with a dynamic source (release feed,
    // Packagist mirror, or DB-driven settings) so a new Mautic release surfaces here
    // automatically. Spec note: "how is backend going to identify a new Mautic version
    // is released, so it appears for selection on the screen?".
    private const SUPPORTED_VERSIONS = [
        '5.2',
        '5.1',
        '5.0',
        '4.4',
    ];

    public function list(): JsonResponse
    {
        return $this->json(['versions' => self::SUPPORTED_VERSIONS]);
    }
}
