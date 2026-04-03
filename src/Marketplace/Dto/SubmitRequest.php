<?php

declare(strict_types=1);

namespace App\Marketplace\Dto;

use App\Marketplace\Exception\SubmitValidationException;
use Symfony\Component\HttpFoundation\InputBag;

final class SubmitRequest
{
    public function __construct(
        public readonly string $assetUrl,
    ) {
    }

    /**
     * @param InputBag<string> $payload
     *
     * @throws SubmitValidationException
     */
    public static function fromPayload(InputBag $payload): self
    {
        $assetUrl = trim($payload->getString('asset_url'));

        if ('' === $assetUrl) {
            throw new SubmitValidationException('Asset URL is required.');
        }

        if (!filter_var($assetUrl, \FILTER_VALIDATE_URL)) {
            throw new SubmitValidationException('Asset URL must be a valid URL.');
        }

        if (!str_starts_with($assetUrl, 'https://')) {
            throw new SubmitValidationException('Asset URL must use HTTPS.');
        }

        return new self($assetUrl);
    }
}
