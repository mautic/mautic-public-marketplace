<?php

declare(strict_types=1);

namespace App\Marketplace\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class SubmitRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Asset URL is required.')]
        #[Assert\Url(message: 'Asset URL must be a valid URL.')]
        #[Assert\Regex(pattern: '/^https:\/\//', message: 'Asset URL must use HTTPS.')]
        public readonly string $asset_url = '',
    ) {
    }
}
