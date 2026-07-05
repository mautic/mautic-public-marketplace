<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth0\Auth0User;
use App\Formatter\LanguageFilterFormatter;
use App\Formatter\MauticVersionConstraintFormatter;
use App\Marketplace\Dto\PackageDetail;
use App\Marketplace\LanguageOptions;
use App\Marketplace\MarketplaceApiClient;
use App\Marketplace\MauticVersionsProvider;
use App\Marketplace\PackageDetailPageContextBuilder;
use App\Supabase\Exception\SupabaseApiException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MarketplaceController extends AbstractController
{
    private const PACKAGE_TYPES = [
        'mautic-plugin',
        'mautic-theme',
        'mautic-resource',
    ];

    public function __construct(
        private readonly MarketplaceApiClient $apiClient,
        private readonly PackageDetailPageContextBuilder $detailPageContextBuilder,
        private readonly LanguageFilterFormatter $languageFilterFormatter,
        private readonly MauticVersionConstraintFormatter $mauticVersionConstraintFormatter,
        private readonly LanguageOptions $languageOptions,
        private readonly MauticVersionsProvider $mauticVersionsProvider,
    ) {
    }

    public function homepage(): Response
    {
        return $this->render('marketplace/homepage.html.twig');
    }

    public function uploadPackage(): Response
    {
        return $this->render('marketplace/upload/package.html.twig', [
            'language_options' => $this->languageOptions->all(),
            // Backend-managed major versions (from Packagist, cached) so the "Works with"
            // checkboxes track new Mautic releases without editing the template.
            'mautic_versions' => $this->mauticVersionsProvider->getSupportedVersions(),
        ]);
    }

    public function index(Request $request): Response
    {
        $limit = $this->toInt($request->query->get('limit'), 10);
        $offset = $this->toInt($request->query->get('offset'), 0);
        $orderBy = (string) $request->query->get('orderby', 'downloads');
        $orderDir = (string) $request->query->get('orderdir', 'desc');
        $type = $request->query->get('type');
        $query = $request->query->get('query');
        $mauticVersions = $this->normalizeMauticVersions($request->query->all()['mautic'] ?? null);
        $languages = $this->normalizeLanguages($request->query->all()['language'] ?? null);
        $ratingFilter = $this->normalizeRatingFilter($request->query->get('rating'));
        $minimumRating = $this->minimumRatingForFilter($ratingFilter);
        $unratedOnly = 'unrated' === $ratingFilter;
        $currentUser = $this->getUser();
        $thingsIRated = null !== $currentUser && $this->isChecked($request->query->all()['things_i_rated'] ?? null);
        $ratedBy = $thingsIRated ? $currentUser->getUserIdentifier() : null;
        $mySubmittedPackages = null !== $currentUser && $this->isChecked($request->query->all()['my_submitted_packages'] ?? null);
        $submittedBy = $mySubmittedPackages ? $currentUser->getUserIdentifier() : null;
        $dateRange = $request->query->get('date_range');
        $popularity = $request->query->get('popularity');

        try {
            $result = $this->apiClient->listPackages(
                $limit,
                $offset,
                $orderBy,
                $orderDir,
                \is_string($type) ? $type : null,
                \is_string($query) ? $query : null,
                $mauticVersions,
                $languages,
                $minimumRating,
                $unratedOnly,
                $ratedBy,
                $submittedBy,
                \is_string($dateRange) ? $dateRange : null,
                \is_string($popularity) ? $popularity : null,
            );
            $typeCounts = $this->buildTypeCounts(
                \is_string($query) ? $query : null,
                $mauticVersions,
                $languages,
                $minimumRating,
                $unratedOnly,
                $ratedBy,
                $submittedBy,
                \is_string($dateRange) ? $dateRange : null,
                \is_string($popularity) ? $popularity : null,
            );
            $compatibleMauticVersions = $this->apiClient->getCompatibleMauticVersions();
            $availableLanguages = $this->apiClient->getAvailableLanguages(
                \is_string($type) ? $type : null,
                \is_string($query) ? $query : null,
                $mauticVersions,
                $minimumRating,
                $unratedOnly,
                $ratedBy,
                $submittedBy,
                \is_string($dateRange) ? $dateRange : null,
                \is_string($popularity) ? $popularity : null,
            );
        } catch (SupabaseApiException $exception) {
            return $this->render('marketplace/index.html.twig', [
                'error' => $exception->getMessage(),
                'result' => null,
                'type_counts' => $this->emptyTypeCounts(),
                'mautic_versions' => [],
                'mautic_version_labels' => [],
                'language_options' => [],
                'filters' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'orderby' => $orderBy,
                    'orderdir' => $orderDir,
                    'type' => $type,
                    'query' => $query,
                    'mautic' => $mauticVersions,
                    'language' => $languages,
                    'rating' => $ratingFilter,
                    'things_i_rated' => $thingsIRated,
                    'my_submitted_packages' => $mySubmittedPackages,
                    'date_range' => $dateRange,
                    'popularity' => $popularity,
                ],
            ], new Response('', Response::HTTP_BAD_GATEWAY));
        }

        return $this->render('marketplace/index.html.twig', [
            'error' => null,
            'result' => $result,
            'type_counts' => $typeCounts,
            'mautic_versions' => $compatibleMauticVersions,
            'mautic_version_labels' => $this->buildMauticVersionLabels($compatibleMauticVersions),
            'language_options' => $this->languageFilterFormatter->buildOptions($availableLanguages),
            'filters' => [
                'limit' => $limit,
                'offset' => $offset,
                'orderby' => $orderBy,
                'orderdir' => $orderDir,
                'type' => $type,
                'query' => $query,
                'mautic' => $mauticVersions,
                'language' => $languages,
                'rating' => $ratingFilter,
                'things_i_rated' => $thingsIRated,
                'my_submitted_packages' => $mySubmittedPackages,
                'date_range' => $dateRange,
                'popularity' => $popularity,
            ],
        ]);
    }

    public function detail(string $package): Response
    {
        $page = $this->detailPageContextBuilder->build($package);

        if (Response::HTTP_NOT_FOUND === $page['status_code']) {
            throw $this->createNotFoundException((string) $page['context']['error']);
        }

        return $this->render('marketplace/detail.html.twig', $page['context'], new Response('', $page['status_code']));
    }

    public function download(string $package): RedirectResponse
    {
        try {
            $detail = $this->apiClient->getPackage($package);
        } catch (SupabaseApiException) {
            $detail = null;
        }

        if (null === $detail) {
            throw $this->createNotFoundException(\sprintf('Package "%s" not found.', $package));
        }

        [$distUrl, $version] = $this->latestDist($detail->versions ?? []);

        if (null === $distUrl) {
            throw $this->createNotFoundException(\sprintf('Package "%s" has no downloadable archive.', $package));
        }

        $user = $this->getUser();
        if ($user instanceof Auth0User) {
            try {
                $this->apiClient->recordDownload($detail->name, $version, $user->getUserIdentifier());
            } catch (SupabaseApiException) {
                // History is best-effort; never block the download on it.
            }
        }

        return new RedirectResponse($this->downloadUrl($detail, $distUrl, $version));
    }

    /**
     * Builds the browser-facing archive URL. For marketplace-hosted archives, the
     * storage ?download parameter names the saved file after the package (e.g.
     * "Open House Re-engagement 1.0.0.zip") instead of the bare version number.
     */
    private function downloadUrl(PackageDetail $detail, string $distUrl, ?string $version): string
    {
        $url = $this->apiClient->toPublicStorageUrl($distUrl);

        if (!str_contains($url, '/storage/v1/object/public/')) {
            // Externally hosted dist — leave the URL untouched.
            return $url;
        }

        $name = trim($detail->displayName ?? '');
        if ('' === $name) {
            $name = str_contains($detail->name, '/') ? explode('/', $detail->name, 2)[1] : $detail->name;
        }

        // Strip characters that are unsafe in filenames across platforms.
        $name = trim((string) preg_replace('#[/\\\\:*?"<>|]+#', ' ', $name));
        $filename = trim($name.' '.($version ?? '')).'.zip';

        return $url.'?download='.rawurlencode($filename);
    }

    /**
     * Resolves the archive URL of the latest downloadable version, preferring
     * stable versions over dev ones — mirroring how Mautic picks a version
     * when installing from the marketplace API.
     *
     * @param array<mixed> $versions
     *
     * @return array{0: ?string, 1: ?string} Dist URL and version string
     */
    private function latestDist(array $versions): array
    {
        $fallback = [null, null];

        foreach ($versions as $version) {
            if (!\is_array($version) || !\is_string($version['dist']['url'] ?? null) || '' === $version['dist']['url']) {
                continue;
            }

            $name = \is_string($version['version'] ?? null) ? $version['version'] : null;

            if (null === $name || !str_starts_with($name, 'dev-')) {
                return [$version['dist']['url'], $name];
            }

            if ([null, null] === $fallback) {
                $fallback = [$version['dist']['url'], $name];
            }
        }

        return $fallback;
    }

    private function toInt(mixed $value, int $default): int
    {
        if (null === $value || '' === $value) {
            return $default;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param list<string> $mauticVersions
     * @param list<string> $languages
     *
     * @return array<string, int>
     */
    private function buildTypeCounts(?string $query, array $mauticVersions, array $languages, ?int $minimumRating, bool $unratedOnly, ?string $ratedBy, ?string $submittedBy, ?string $dateRange, ?string $popularity): array
    {
        $baseResult = $this->apiClient->listPackages(
            1,
            0,
            'downloads',
            'desc',
            null,
            $query,
            $mauticVersions,
            $languages,
            $minimumRating,
            $unratedOnly,
            $ratedBy,
            $submittedBy,
            $dateRange,
            $popularity,
        );

        $total = $baseResult->total ?? \count($baseResult->items);
        if ($total <= 0) {
            return $this->emptyTypeCounts();
        }

        $fullResult = $this->apiClient->listPackages(
            $total,
            0,
            'downloads',
            'desc',
            null,
            $query,
            $mauticVersions,
            $languages,
            $minimumRating,
            $unratedOnly,
            $ratedBy,
            $submittedBy,
            $dateRange,
            $popularity,
        );

        $counts = $this->emptyTypeCounts();
        foreach ($fullResult->items as $item) {
            if (null === $item->type || !\array_key_exists($item->type, $counts)) {
                continue;
            }

            ++$counts[$item->type];
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function emptyTypeCounts(): array
    {
        return array_fill_keys(self::PACKAGE_TYPES, 0);
    }

    /**
     * @param list<string> $versions
     *
     * @return array<string, string>
     */
    private function buildMauticVersionLabels(array $versions): array
    {
        $labels = [];
        foreach ($versions as $version) {
            $labels[$version] = $this->mauticVersionConstraintFormatter->format($version);
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    private function normalizeMauticVersions(mixed $value): array
    {
        if (\is_string($value)) {
            $value = [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        $versions = [];
        foreach ($value as $item) {
            if (!\is_string($item)) {
                continue;
            }

            $item = trim($item);
            if ('' === $item) {
                continue;
            }

            $versions[] = $item;
        }

        return array_values(array_unique($versions));
    }

    /**
     * @return list<string>
     */
    private function normalizeLanguages(mixed $value): array
    {
        if (\is_string($value)) {
            $value = [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        $languages = [];
        foreach ($value as $item) {
            if (!\is_string($item)) {
                continue;
            }

            $canonical = $this->languageFilterFormatter->canonicalize($item);
            if (null === $canonical) {
                continue;
            }

            $languages[] = $canonical;
        }

        return array_values(array_unique($languages));
    }

    private function normalizeRatingFilter(mixed $value): ?string
    {
        if ('unrated' === $value) {
            return 'unrated';
        }

        if (null === $value || '' === $value || !is_numeric($value)) {
            return null;
        }

        $rating = (int) $value;

        return $rating >= 1 && $rating <= 5 ? (string) $rating : null;
    }

    private function minimumRatingForFilter(?string $ratingFilter): ?int
    {
        if (null === $ratingFilter || 'unrated' === $ratingFilter) {
            return null;
        }

        return (int) $ratingFilter;
    }

    private function isChecked(mixed $value): bool
    {
        if (\is_array($value)) {
            return \in_array('1', $value, true);
        }

        return '1' === $value || 1 === $value || true === $value;
    }
}
