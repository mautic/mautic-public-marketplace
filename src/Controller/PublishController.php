<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

final class PublishController extends AbstractController
{
    public function upload(): Response
    {
        // Renders the popup target for Mautic's push flow. Auth is enforced via
        // security.yaml access_control for ^/publish, so reaching this method
        // already guarantees an authenticated marketplace user.
        return $this->render('publish/upload.html.twig');
    }
}
