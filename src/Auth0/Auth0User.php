<?php

declare(strict_types=1);

namespace App\Auth0;

use Symfony\Component\Security\Core\User\UserInterface;

final class Auth0User implements UserInterface
{
    public function __construct(
        private readonly string $sub,
        private readonly ?string $name,
        private readonly ?string $email,
        private readonly ?string $picture,
    ) {
    }

    public function getUserIdentifier(): string
    {
        return $this->sub;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPicture(): ?string
    {
        return $this->picture;
    }
}
