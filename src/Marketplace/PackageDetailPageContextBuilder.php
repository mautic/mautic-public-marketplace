<?php

declare(strict_types=1);

namespace App\Marketplace;

use App\Marketplace\Dto\PackageDetail;
use App\Supabase\Exception\SupabaseApiException;
use Symfony\Component\HttpFoundation\Response;

final class PackageDetailPageContextBuilder
{
    public function __construct(
        private readonly MarketplaceApiClient $apiClient,
    ) {
    }

    /**
     * @param array{rating:string,review:string} $reviewForm
     * @param array<string,string>               $reviewErrors
     *
     * @return array{context: array<string, mixed>, status_code: int}
     */
    public function build(string $packageName, array $reviewForm = ['rating' => '0', 'review' => ''], array $reviewErrors = []): array
    {
        $detail = null;
        $error = null;
        $statusCode = Response::HTTP_OK;

        try {
            $detail = $this->apiClient->getPackage($packageName);
        } catch (SupabaseApiException $exception) {
            $error = $exception->getMessage();
            $statusCode = Response::HTTP_BAD_GATEWAY;
        }

        if (null === $error && !$detail instanceof PackageDetail) {
            $error = 'Package not found.';
            $statusCode = Response::HTTP_NOT_FOUND;
        }

        /** @var array<mixed> $reviews */
        $reviews = null !== $detail ? ($detail->reviews ?? []) : [];
        $displayReviews = $this->buildDisplayReviews($reviews);
        $averageRating = $this->calculateAverageRating($reviews);

        return [
            'context' => [
                'error' => $error,
                'package' => $detail,
                'name' => $packageName,
                'review_form' => $reviewForm,
                'review_errors' => $reviewErrors,
                'display_reviews' => $displayReviews,
                'average_rating' => $averageRating,
            ],
            'status_code' => $statusCode,
        ];
    }

    /**
     * @param array<mixed> $reviews
     *
     * @return list<array{text: ?string, author: ?string, rating: ?int}>
     */
    private function buildDisplayReviews(array $reviews): array
    {
        $result = [];

        foreach ($reviews as $review) {
            if (!\is_array($review)) {
                continue;
            }

            $rating = isset($review['rating']) ? (int) $review['rating'] : null;
            $text = $review['review'] ?? null;

            if (null === $rating && null === $text) {
                continue;
            }

            $result[] = [
                'text' => $text,
                'author' => $review['user'] ?? $review['name'] ?? null,
                'rating' => $rating,
            ];
        }

        return $result;
    }

    /**
     * @param array<mixed> $reviews
     */
    private function calculateAverageRating(array $reviews): ?float
    {
        $ratings = [];

        foreach ($reviews as $review) {
            if (\is_array($review) && isset($review['rating'])) {
                $ratings[] = (int) $review['rating'];
            }
        }

        return [] === $ratings ? null : array_sum($ratings) / \count($ratings);
    }
}
