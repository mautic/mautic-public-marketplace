<?php

declare(strict_types=1);

namespace App\Tests\Marketplace\Dto;

use App\Marketplace\Dto\ReviewRequest;
use App\Marketplace\Exception\ReviewValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class ReviewRequestTest extends TestCase
{
    public function testStripsHtmlFromReviewText(): void
    {
        $request = ReviewRequest::fromPayload($this->payload(
            '<img src onerror=alert(1)> This template saved me a lot of setup time and works well.',
        ));

        self::assertSame('This template saved me a lot of setup time and works well.', $request->review);
    }

    public function testKeepsAngleBracketsThatAreNotMarkup(): void
    {
        $text = 'Works fine as long as your list has < 500 contacts, otherwise it slows down a lot.';

        self::assertSame($text, ReviewRequest::fromPayload($this->payload($text))->review);
    }

    /**
     * Length is checked after stripping, so markup can't pad a review up to the minimum.
     */
    public function testRejectsTextThatOnlyReachesTheMinimumThroughMarkup(): void
    {
        $this->expectException(ReviewValidationException::class);

        ReviewRequest::fromPayload($this->payload('<div class="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa">Nice</div>'));
    }

    public function testRejectsTextThatIsNothingButMarkup(): void
    {
        $this->expectException(ReviewValidationException::class);

        ReviewRequest::fromPayload($this->payload('<script>alert(1)</script>'));
    }

    /**
     * @return InputBag<string>
     */
    private function payload(string $review, string $rating = '4'): InputBag
    {
        /** @var InputBag<string> $payload */
        $payload = new InputBag(['rating' => $rating, 'review' => $review]);

        return $payload;
    }
}
