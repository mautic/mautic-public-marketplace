<?php

declare(strict_types=1);

namespace App\Marketplace\Dto;

final class PackageDetail
{
    /**
     * @param array<mixed>|null $downloads
     * @param array<mixed>|null $maintainers
     * @param array<mixed>|null $tags
     * @param array<mixed>|null $reviews
     * @param array<mixed>|null $versions
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $displayName,
        public readonly ?string $description,
        public readonly ?string $type,
        public readonly ?string $repository,
        public readonly ?int $githubStars,
        public readonly ?int $githubWatchers,
        public readonly ?int $githubForks,
        public readonly ?int $githubOpenIssues,
        public readonly ?string $language,
        public readonly ?int $dependents,
        public readonly ?int $suggesters,
        public readonly ?array $downloads,
        public readonly ?int $favers,
        public readonly ?string $url,
        public readonly ?bool $isReviewed,
        public readonly ?bool $latestMauticSupport,
        public readonly ?array $maintainers,
        public readonly ?array $tags,
        public readonly ?string $time,
        public readonly ?array $reviews,
        public readonly ?array $versions,
        public readonly ?string $bannerURL = null,
        /** @var list<array{src: string, alt: string}>|null */
        public readonly ?array $gallery = null,
        /** @var list<string>|null Author-selected language names, e.g. ["Czech", "English"] */
        public readonly ?array $languages = null,
        public readonly ?string $license = null,
        public readonly ?string $pricingModel = null,
        public readonly ?float $price = null,
        public readonly ?string $currency = null,
    ) {
    }

    public function isPaid(): bool
    {
        return 'paid' === $this->pricingModel && null !== $this->price && $this->price > 0;
    }
}
