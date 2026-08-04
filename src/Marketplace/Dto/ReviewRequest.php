<?php

declare(strict_types=1);

namespace App\Marketplace\Dto;

use App\Marketplace\Exception\ReviewValidationException;
use Symfony\Component\HttpFoundation\InputBag;

final class ReviewRequest
{
    private const MINIMUM_REVIEW_LENGTH = 50;
    private const MAXIMUM_REVIEW_LENGTH = 5000;

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

        // Reviews are plain text, so strip tags on the way in — a consumer that trusts our API
        // response shouldn't inherit a payload from us. Lone angle brackets stay ("1 < 2" is
        // ordinary prose, and Twig escapes it anyway).
        $review = trim(strip_tags($payload->getString('review')));

        if ($rating < 1 || $rating > 5) {
            throw new ReviewValidationException('Rating must be between 1 and 5.');
        }

        if ('' === $review) {
            throw new ReviewValidationException('Review text is required.');
        }

        if (mb_strlen($review) < self::MINIMUM_REVIEW_LENGTH) {
            throw new ReviewValidationException(\sprintf('Review text must be at least %d characters.', self::MINIMUM_REVIEW_LENGTH));
        }

        if (mb_strlen($review) > self::MAXIMUM_REVIEW_LENGTH) {
            throw new ReviewValidationException(\sprintf('Review text must be at most %d characters.', self::MAXIMUM_REVIEW_LENGTH));
        }

        return new self($rating, $review);
    }
}
