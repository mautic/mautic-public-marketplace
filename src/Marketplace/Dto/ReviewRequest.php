<?php

declare(strict_types=1);

namespace App\Marketplace\Dto;

use App\Marketplace\Exception\ReviewValidationException;
use Symfony\Component\HttpFoundation\InputBag;

final class ReviewRequest
{
    public function __construct(
        public readonly int $rating,
        public readonly string $review,
    ) {
    }

    /**
     * @param InputBag<string> $payload
     *
     * @throws ReviewValidationException
     */
    public static function fromPayload(InputBag $payload): self
    {
        $rating = $payload->getInt('rating');
        $review = trim($payload->getString('review'));

        if ($rating < 1 || $rating > 5) {
            throw new ReviewValidationException('Rating must be between 1 and 5.');
        }

        if ('' === $review) {
            throw new ReviewValidationException('Review text is required.');
        }

        return new self($rating, $review);
    }
}
