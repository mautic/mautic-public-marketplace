<?php

declare(strict_types=1);

namespace App\Marketplace;

use App\Marketplace\Dto\PackageDetail;
use App\Marketplace\Dto\PackageListResult;
use App\Marketplace\Dto\PackageSummary;
use App\Marketplace\Dto\ReviewRequest;
use App\Supabase\Exception\SupabaseApiException;
use App\Supabase\SupabaseClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Intl\Languages;

final class MarketplaceApiClient
{
    public function __construct(
        private readonly SupabaseClient $supabaseClient,
        #[Autowire(env: 'SUPABASE_API_BASE')]
        private readonly string $supabaseBaseUrl,
        // Browser-facing storage host for public image URLs. Empty in production, where
        // SUPABASE_API_BASE is already public; set to the host-mapped port (e.g.
        // http://127.0.0.1:8000) in local dev, where the API base is an internal Docker host.
        #[Autowire(env: 'SUPABASE_PUBLIC_URL')]
        private readonly string $supabasePublicUrl = '',
    ) {
    }

    /**
     * @param list<string> $mauticVersions
     * @param list<string> $languages
     */
    public function listPackages(
        int $limit = 10,
        int $offset = 0,
        string $orderBy = 'downloads',
        string $orderDir = 'desc',
        ?string $type = null,
        ?string $query = null,
        array $mauticVersions = [],
        array $languages = [],
        ?int $minimumRating = null,
        bool $unratedOnly = false,
        ?string $ratedBy = null,
        ?string $submittedBy = null,
        ?string $dateRange = null,
        ?string $popularity = null,
    ): PackageListResult {
        $params = [
            '_limit' => $limit,
            '_offset' => $offset,
            '_orderby' => $orderBy,
            '_orderdir' => $orderDir,
        ];

        if (null !== $query && '' !== $query) {
            $params['_query'] = $query;
        }

        if (null !== $type && '' !== $type) {
            $params['_type'] = $type;
        }

        if ([] !== $mauticVersions) {
            $params['_smv'] = $mauticVersions;
        }

        if ([] !== $languages) {
            $params['_language'] = $languages;
        }

        if (null !== $minimumRating) {
            $params['_minimum_rating'] = $minimumRating;
        }

        if ($unratedOnly) {
            $params['_unrated_only'] = true;
        }

        if (null !== $ratedBy && '' !== $ratedBy) {
            $params['_rated_by'] = $ratedBy;
        }

        if (null !== $submittedBy && '' !== $submittedBy) {
            $params['_submitted_by'] = $submittedBy;
        }

        if (null !== $dateRange && '' !== $dateRange) {
            $params['_date_range'] = $dateRange;
        }

        if (null !== $popularity && '' !== $popularity) {
            $params['_popularity'] = $popularity;
        }

        $data = $this->supabaseClient->rpc('/rest/v1/rpc/get_view', $params);

        $payload = $this->normalizeListPayload($data);
        $rows = $payload['rows'];
        $total = $payload['total'];

        $items = [];
        foreach ($rows as $row) {
            if (!isset($row['name'])) {
                continue;
            }
            $items[] = new PackageSummary(
                (string) $row['name'],
                $row['displayname'] ?? null,
                $row['description'] ?? null,
                $row['type'] ?? null,
                $row['repository'] ?? null,
                $this->toInt($row['github_stars'] ?? null),
                $this->toInt($row['github_forks'] ?? null),
                $this->toInt($row['github_open_issues'] ?? null),
                $row['language'] ?? null,
                $this->toInt($row['favers'] ?? null),
                $this->toInt($row['downloads'] ?? null),
                $this->toFloat($row['average_rating'] ?? null),
                $this->toInt($row['total_review'] ?? null),
                $this->toBool($row['latest_mautic_support'] ?? null),
                $row['validation_errors'] ?? null,
                $this->toDateTime($row['time'] ?? null),
                $this->toBannerUrl($row['banner_url'] ?? null),
                isset($row['headline']) ? (string) $row['headline'] : null,
                isset($row['pricing_model']) ? (string) $row['pricing_model'] : null,
                $this->toFloat($row['price'] ?? null),
                isset($row['currency']) ? (string) $row['currency'] : null,
            );
        }

        return new PackageListResult($items, $limit, $offset, $total);
    }

    /**
     * @param list<string> $mauticVersions
     *
     * @return list<string>
     */
    public function getAvailableLanguages(
        ?string $type = null,
        ?string $query = null,
        array $mauticVersions = [],
        ?int $minimumRating = null,
        bool $unratedOnly = false,
        ?string $ratedBy = null,
        ?string $submittedBy = null,
        ?string $dateRange = null,
        ?string $popularity = null,
    ): array {
        $params = [];

        if (null !== $query && '' !== $query) {
            $params['_query'] = $query;
        }

        if (null !== $type && '' !== $type) {
            $params['_type'] = $type;
        }

        if ([] !== $mauticVersions) {
            $params['_smv'] = $mauticVersions;
        }

        if (null !== $minimumRating) {
            $params['_minimum_rating'] = $minimumRating;
        }

        if ($unratedOnly) {
            $params['_unrated_only'] = true;
        }

        if (null !== $ratedBy && '' !== $ratedBy) {
            $params['_rated_by'] = $ratedBy;
        }

        if (null !== $submittedBy && '' !== $submittedBy) {
            $params['_submitted_by'] = $submittedBy;
        }

        if (null !== $dateRange && '' !== $dateRange) {
            $params['_date_range'] = $dateRange;
        }

        if (null !== $popularity && '' !== $popularity) {
            $params['_popularity'] = $popularity;
        }

        $data = $this->supabaseClient->rpc('/rest/v1/rpc/get_available_languages', $params);

        if (!\is_array($data)) {
            return [];
        }

        $values = [];
        foreach ($data as $value) {
            if (\is_string($value) && '' !== trim($value)) {
                $values[] = trim($value);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @return list<string>
     */
    public function getCompatibleMauticVersions(): array
    {
        $data = $this->supabaseClient->rpc('/rest/v1/rpc/get_compatible_mautic_versions', []);

        if (!\is_array($data)) {
            return [];
        }

        $values = [];
        foreach ($data as $value) {
            if (\is_string($value) && '' !== $value) {
                $values[] = $value;
            }
        }

        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    public function getPackage(string $packageName): ?PackageDetail
    {
        $params = [
            'packag_name' => $packageName,
        ];

        $data = $this->supabaseClient->query('GET', '/rest/v1/rpc/get_pack', $params);

        if (null === $data || [] === $data) {
            return null;
        }

        $row = $data['package'] ?? null;
        if (!\is_array($row) || !isset($row['name'])) {
            throw new SupabaseApiException('Unexpected response from Supabase get_pack.');
        }

        $versions = $this->toArray($row['versions'] ?? null);
        $tags = $this->toArray($row['tags'] ?? $row['keywords'] ?? null);
        if (null === $tags && null !== $versions) {
            $keywords = [];
            foreach ($versions as $version) {
                if (!\is_array($version) || !\is_array($version['keywords'] ?? null)) {
                    continue;
                }

                foreach ($version['keywords'] as $keyword) {
                    if (\is_scalar($keyword)) {
                        $keywords[] = (string) $keyword;
                    }
                }
            }

            $keywords = array_values(array_unique($keywords));
            $tags = [] === $keywords ? null : $keywords;
        }

        return new PackageDetail(
            (string) $row['name'],
            $row['displayname'] ?? null,
            $row['description'] ?? null,
            $row['type'] ?? null,
            $row['repository'] ?? null,
            $this->toInt($row['github_stars'] ?? null),
            $this->toInt($row['github_watchers'] ?? null),
            $this->toInt($row['github_forks'] ?? null),
            $this->toInt($row['github_open_issues'] ?? null),
            $row['language'] ?? null,
            $this->toInt($row['dependents'] ?? null),
            $this->toInt($row['suggesters'] ?? null),
            $this->toArray($row['downloads'] ?? null),
            $this->toInt($row['favers'] ?? null),
            $row['url'] ?? null,
            $this->toBool($row['isreviewed'] ?? null),
            $this->toBool($row['latest_mautic_support'] ?? null),
            $this->toArray($row['maintainers'] ?? null),
            $tags,
            isset($row['time']) ? (string) $row['time'] : null,
            $this->toArray($row['reviews'] ?? null),
            $versions,
            $this->toBannerUrl($row['banner_url'] ?? null),
            $this->toGalleryImages($row['gallery'] ?? null),
            $this->toLanguageNames($row['languages'] ?? null),
            $this->toLicense($versions),
            isset($row['pricing_model']) ? (string) $row['pricing_model'] : null,
            $this->toFloat($row['price'] ?? null),
            isset($row['currency']) ? (string) $row['currency'] : null,
        );
    }

    /**
     * Resolves the author-selected language codes (e.g. "cs") to their English names
     * (e.g. "Czech") for display, falling back to the raw value when it isn't a known
     * ISO code (so already-named or custom values still show).
     *
     * @return list<string>|null
     */
    private function toLanguageNames(mixed $languages): ?array
    {
        if (!\is_array($languages)) {
            return null;
        }

        $names = [];
        foreach ($languages as $value) {
            if (!\is_string($value) || '' === $value) {
                continue;
            }

            $names[] = Languages::exists($value) ? Languages::getName($value) : $value;
        }

        return [] === $names ? null : $names;
    }

    /** Picks the per-version composer license (array or string) and returns it for display, e.g. "MIT". */
    private function toLicense(mixed $versions): ?string
    {
        if (!\is_array($versions)) {
            return null;
        }

        foreach ($versions as $version) {
            $license = \is_array($version) ? ($version['license'] ?? null) : null;
            if (\is_array($license)) {
                $license = implode(', ', array_filter(array_map('strval', $license)));
            }
            if (\is_string($license) && '' !== $license) {
                return $license;
            }
        }

        return null;
    }

    /**
     * Maps the stored gallery rows ({url, alt}) to what the detail template expects
     * ({src, alt}), building a browser-reachable URL for each image the same way as
     * the banner.
     *
     * @return list<array{src: string, alt: string}>|null
     */
    private function toGalleryImages(mixed $gallery): ?array
    {
        if (!\is_array($gallery)) {
            return null;
        }

        $images = [];
        foreach ($gallery as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $url = $item['url'] ?? $item['src'] ?? null;
            $src = \is_string($url) ? $this->toBannerUrl($url) : null;
            if (null === $src) {
                continue;
            }

            $images[] = [
                'src' => $src,
                'alt' => \is_string($item['alt'] ?? null) ? $item['alt'] : '',
            ];
        }

        return [] === $images ? null : $images;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPackageByName(string $name): ?array
    {
        $data = $this->supabaseClient->query('GET', '/rest/v1/packages', [
            'name' => 'eq.'.$name,
            'select' => '*',
        ]);

        if (!\is_array($data) || [] === $data) {
            return null;
        }

        return \is_array($data[0] ?? null) ? $data[0] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPackageByCampaignUuid(string $campaignUuid): ?array
    {
        $data = $this->supabaseClient->query('GET', '/rest/v1/packages', [
            'campaign_uuid' => 'eq.'.$campaignUuid,
            'select' => '*',
        ]);

        if (!\is_array($data) || [] === $data) {
            return null;
        }

        return \is_array($data[0] ?? null) ? $data[0] : null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function createPackage(array $data): array
    {
        $result = $this->supabaseClient->mutate('POST', '/rest/v1/packages', $data);

        return \is_array($result) ? ($result[0] ?? $result) : [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function updatePackage(string $name, array $data): array
    {
        $result = $this->supabaseClient->mutate(
            'PATCH',
            '/rest/v1/packages?name=eq.'.urlencode($name),
            $data,
        );

        return \is_array($result) ? ($result[0] ?? $result) : [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function upsertVersion(array $data): array
    {
        $result = $this->supabaseClient->mutate(
            'POST',
            '/rest/v1/versions?on_conflict=package_name,version',
            $data,
            ['Prefer' => 'return=representation,resolution=merge-duplicates'],
        );

        return \is_array($result) ? ($result[0] ?? $result) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUserReviews(string $auth0UserId): array
    {
        $data = $this->supabaseClient->query('GET', '/rest/v1/reviews', [
            'auth0_user_id' => 'eq.'.$auth0UserId,
            'select' => '*',
            'order' => 'created_at.desc',
        ]);

        if (!\is_array($data)) {
            return [];
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUserUploadedPackages(string $auth0UserId): array
    {
        $data = $this->supabaseClient->query('GET', '/rest/v1/packages', [
            'auth0_user_id' => 'eq.'.$auth0UserId,
            'select' => 'name,displayname,description,type,downloads,favers',
            'order' => 'name.asc',
        ]);

        if (!\is_array($data)) {
            return [];
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUserDownloadHistory(string $auth0UserId): array
    {
        $data = $this->supabaseClient->queryPrivate('GET', '/rest/v1/download_history', [
            'auth0_user_id' => 'eq.'.$auth0UserId,
            'select' => 'id,package_name,package_version,downloaded_at,package:packages(name,displayname,description,type)',
            'order' => 'downloaded_at.desc',
        ]);

        if (!\is_array($data)) {
            return [];
        }

        return $data;
    }

    public function submitReview(string $packageName, string $userId, string $userName, ?string $picture, ReviewRequest $reviewRequest): void
    {
        $this->supabaseClient->mutate('POST', '/rest/v1/reviews', [
            'objectId' => $packageName,
            'auth0_user_id' => $userId,
            'user' => $userName,
            'picture' => $picture,
            'rating' => $reviewRequest->rating,
            'review' => $reviewRequest->review,
        ]);
    }

    /**
     * @return array{stripe_account_id: string, charges_enabled: bool, payouts_enabled: bool, details_submitted: bool}|null
     */
    public function getStripeConnectAccount(string $auth0UserId): ?array
    {
        $data = $this->supabaseClient->queryPrivate('GET', '/rest/v1/stripe_connect_accounts', [
            'auth0_user_id' => 'eq.'.$auth0UserId,
            'select' => 'stripe_account_id,charges_enabled,payouts_enabled,details_submitted',
            'limit' => '1',
        ]);

        if (!\is_array($data) || !isset($data[0]) || !\is_array($data[0]) || !isset($data[0]['stripe_account_id'])) {
            return null;
        }

        $row = $data[0];

        return [
            'stripe_account_id' => (string) $row['stripe_account_id'],
            'charges_enabled' => (bool) ($row['charges_enabled'] ?? false),
            'payouts_enabled' => (bool) ($row['payouts_enabled'] ?? false),
            'details_submitted' => (bool) ($row['details_submitted'] ?? false),
        ];
    }

    public function saveStripeConnectAccount(
        string $auth0UserId,
        string $stripeAccountId,
        bool $chargesEnabled,
        bool $payoutsEnabled,
        bool $detailsSubmitted,
    ): void {
        // Upsert on the auth0_user_id primary key so re-running onboarding refreshes
        // the same row rather than creating duplicates.
        $this->supabaseClient->mutate('POST', '/rest/v1/stripe_connect_accounts', [
            'auth0_user_id' => $auth0UserId,
            'stripe_account_id' => $stripeAccountId,
            'charges_enabled' => $chargesEnabled,
            'payouts_enabled' => $payoutsEnabled,
            'details_submitted' => $detailsSubmitted,
            'updated_at' => (new \DateTimeImmutable())->format('c'),
        ], ['Prefer' => 'resolution=merge-duplicates,return=representation']);
    }

    public function recordPurchase(
        string $auth0UserId,
        string $packageName,
        string $checkoutSessionId,
        ?string $paymentIntentId,
        ?float $amount,
        ?string $currency,
    ): void {
        // Upsert on the checkout session so repeated webhook deliveries stay idempotent.
        $this->supabaseClient->mutate('POST', '/rest/v1/purchases?on_conflict=stripe_checkout_session_id', [
            'auth0_user_id' => $auth0UserId,
            'package_name' => $packageName,
            'stripe_checkout_session_id' => $checkoutSessionId,
            'stripe_payment_intent_id' => $paymentIntentId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'completed',
        ], ['Prefer' => 'resolution=merge-duplicates,return=representation']);
    }

    /**
     * Reads the checkout-relevant pricing fields straight from the packages row. Kept
     * out of the public get_pack payload so Stripe ids are never exposed to the browser.
     *
     * @return array{pricing_model: string, price: ?float, currency: ?string, stripe_price_id: ?string, vendor_stripe_account_id: ?string}|null
     */
    public function getPackageCheckoutData(string $packageName): ?array
    {
        $data = $this->supabaseClient->queryPrivate('GET', '/rest/v1/packages', [
            'name' => 'eq.'.$packageName,
            'select' => 'pricing_model,price,currency,stripe_price_id,vendor_stripe_account_id',
            'limit' => '1',
        ]);

        if (!\is_array($data) || !isset($data[0]) || !\is_array($data[0])) {
            return null;
        }

        $row = $data[0];

        return [
            'pricing_model' => (string) ($row['pricing_model'] ?? 'free'),
            'price' => isset($row['price']) ? (float) $row['price'] : null,
            'currency' => isset($row['currency']) ? (string) $row['currency'] : null,
            'stripe_price_id' => isset($row['stripe_price_id']) ? (string) $row['stripe_price_id'] : null,
            'vendor_stripe_account_id' => isset($row['vendor_stripe_account_id']) ? (string) $row['vendor_stripe_account_id'] : null,
        ];
    }

    public function hasPurchased(string $auth0UserId, string $packageName): bool
    {
        $data = $this->supabaseClient->queryPrivate('GET', '/rest/v1/purchases', [
            'auth0_user_id' => 'eq.'.$auth0UserId,
            'package_name' => 'eq.'.$packageName,
            'status' => 'eq.completed',
            'select' => 'id',
            'limit' => '1',
        ]);

        return \is_array($data) && isset($data[0]);
    }

    public function recordDownload(string $packageName, ?string $version, ?string $auth0UserId): void
    {
        $this->supabaseClient->mutate('POST', '/rest/v1/download_history', [
            'auth0_user_id' => $auth0UserId,
            'package_name' => $packageName,
            'package_version' => $version,
        ]);
    }

    private function toInt(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function toBool(mixed $value): ?bool
    {
        if (null === $value) {
            return null;
        }

        if (\is_bool($value)) {
            return $value;
        }

        if (1 === $value || '1' === $value || 'true' === $value) {
            return true;
        }

        if (0 === $value || '0' === $value || 'false' === $value) {
            return false;
        }

        return null;
    }

    private function toFloat(mixed $value): ?float
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<mixed>|null
     */
    private function toArray(mixed $value): ?array
    {
        return \is_array($value) ? $value : null;
    }

    private function toBannerUrl(?string $path): ?string
    {
        if (null === $path || '' === $path) {
            return null;
        }

        return $this->toPublicStorageUrl($path);
    }

    /**
     * Rebuilds a storage object URL (or bucket-relative path) against the browser-facing
     * storage base. Rows may store URLs with the server-side host (e.g. the in-cluster
     * Supabase hostname), which browsers can't reach — only the object path is kept and
     * the public base is prepended.
     */
    public function toPublicStorageUrl(string $path): string
    {
        $marker = '/storage/v1/object/public/';
        if (false !== ($pos = strpos($path, $marker))) {
            $path = substr($path, $pos + \strlen($marker));
        } elseif (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            // Absolute URL outside marketplace storage (e.g. an externally hosted dist) — leave as-is.
            return $path;
        }

        $base = '' !== $this->supabasePublicUrl ? $this->supabasePublicUrl : $this->supabaseBaseUrl;

        return \sprintf('%s/storage/v1/object/public/%s', rtrim($base, '/'), ltrim($path, '/'));
    }

    private function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return array{rows: array<int, array<string, mixed>>, total: ?int}
     */
    private function normalizeListPayload(array $data): array
    {
        if ([] === $data) {
            return ['rows' => [], 'total' => 0];
        }

        if ($this->isAssoc($data) && \array_key_exists('results', $data)) {
            $rows = $this->normalizeRows($this->toArray($data['results']) ?? []);
            $total = $this->toInt($data['total'] ?? null);

            return ['rows' => $rows, 'total' => $total];
        }

        $first = $data[0] ?? null;
        if (\is_array($first) && \array_key_exists('results', $first)) {
            $rows = $this->normalizeRows($this->toArray($first['results']) ?? []);
            $total = $this->toInt($first['total'] ?? null);

            return ['rows' => $rows, 'total' => $total];
        }

        $rows = $this->normalizeRows($data);

        return ['rows' => $rows, 'total' => null];
    }

    /**
     * @param array<int|string, mixed> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        if ([] === $rows) {
            return [];
        }

        if ($this->isAssoc($rows)) {
            return [$rows];
        }

        return $rows;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function isAssoc(array $value): bool
    {
        if ([] === $value) {
            return false;
        }

        return array_keys($value) !== range(0, \count($value) - 1);
    }
}
