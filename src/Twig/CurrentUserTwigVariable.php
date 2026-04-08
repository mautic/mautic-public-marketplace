<?php

declare(strict_types=1);

namespace App\Twig;

use App\Auth0\Auth0User;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class CurrentUserTwigVariable
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function getSub(): ?string
    {
        return $this->getUser()?->getUserIdentifier();
    }

    public function getName(): ?string
    {
        return $this->getUser()?->getName();
    }

    public function getEmail(): ?string
    {
        return $this->getUser()?->getEmail();
    }

    public function getPicture(): ?string
    {
        return $this->getUser()?->getPicture();
    }

    private function getUser(): ?Auth0User
    {
        $user = $this->security->getUser();

        return $user instanceof Auth0User ? $user : null;
    }
}
