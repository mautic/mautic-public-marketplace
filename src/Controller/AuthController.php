<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

final class AuthController extends AbstractController
{
    public function callback(): Response
    {
        return $this->render('auth/callback.html.twig');
    }
}
