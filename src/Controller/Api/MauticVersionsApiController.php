<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Marketplace\MauticVersionsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

final class MauticVersionsApiController extends AbstractController
{
    public function __construct(
        private readonly MauticVersionsProvider $versionsProvider,
    ) {
    }

    public function list(): JsonResponse
    {
        return $this->json(['versions' => $this->versionsProvider->getSupportedVersions()]);
    }
}
