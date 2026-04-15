<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublishController extends AbstractController
{
    public function index(Request $request): Response
    {
        $assetUrl = $request->query->getString('asset_url');

        if ('' === $assetUrl) {
            $this->addFlash('error', 'This page is used when sharing a campaign from Mautic.');

            return $this->redirectToRoute('marketplace_index');
        }

        return $this->render('publish/index.html.twig', [
            'asset_url' => $assetUrl,
        ]);
    }
}
