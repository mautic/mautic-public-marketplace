<?php

declare(strict_types=1);

namespace App\Marketplace\Dto;

final class PackageSummary
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $displayName,
        public readonly ?string $description,
        public readonly ?string $type,
        public readonly ?string $repository,
        public readonly ?int $githubStars,
        public readonly ?int $githubForks,
        public readonly ?int $githubOpenIssues,
        public readonly ?string $language,
        public readonly ?int $favers,
        public readonly ?int $downloadsTotal,
        public readonly ?float $averageRating,
        public readonly ?int $totalReviews,
        public readonly ?bool $latestMauticSupport,
        public readonly ?string $validationErrors,
        public readonly ?\DateTimeImmutable $updatedAt,
        public readonly ?string $bannerURL = null,
        public readonly ?string $headline = null,
    ) {
    }
}
