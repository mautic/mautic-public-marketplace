<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

final class ProfileController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'AUTH0_DOMAIN')]
        private readonly string $auth0Domain,
        #[Autowire(env: 'AUTH0_CLIENT_ID')]
        private readonly string $auth0ClientId,
    ) {
    }

    public function index(): Response
    {
        return $this->render('profile/index.html.twig', [
            'auth0_domain' => $this->auth0Domain,
            'auth0_client_id' => $this->auth0ClientId,
        ]);
    }
}
