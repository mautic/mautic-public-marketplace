<?php

declare(strict_types=1);

namespace App\Marketplace\Dto;

use App\Marketplace\Exception\ReviewValidationException;
use Symfony\Component\HttpFoundation\InputBag;

final class ReviewRequest
{
    private const MINIMUM_REVIEW_LENGTH = 50;

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

        if (mb_strlen($review) < self::MINIMUM_REVIEW_LENGTH) {
            throw new ReviewValidationException(\sprintf('Review text must be at least %d characters.', self::MINIMUM_REVIEW_LENGTH));
        }

        return new self($rating, $review);
    }
}
