<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Twig\CurrentUserTwigVariable;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

final class Auth0UserTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly CurrentUserTwigVariable $currentUser,
    ) {
    }

    public function getGlobals(): array
    {
        return [
            'user' => $this->currentUser,
        ];
    }
}
