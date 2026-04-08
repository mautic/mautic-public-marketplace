<?php

declare(strict_types=1);

namespace App\Controller;

use App\Formatter\MauticVersionConstraintFormatter;
use App\Marketplace\MarketplaceApiClient;
use App\Supabase\Exception\SupabaseApiException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        private readonly MauticVersionConstraintFormatter $mauticVersionConstraintFormatter,
        #[Autowire(env: 'AUTH0_DOMAIN')]
        private readonly string $auth0Domain,
        #[Autowire(env: 'AUTH0_CLIENT_ID')]
        private readonly string $auth0ClientId,
    ) {
    }

    public function homepage(): Response
    {
        return $this->render('marketplace/homepage.html.twig');
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
                \is_string($dateRange) ? $dateRange : null,
                \is_string($popularity) ? $popularity : null,
            );
            $typeCounts = $this->buildTypeCounts(
                \is_string($query) ? $query : null,
                $mauticVersions,
                \is_string($dateRange) ? $dateRange : null,
                \is_string($popularity) ? $popularity : null,
            );
            $compatibleMauticVersions = $this->apiClient->getCompatibleMauticVersions();
        } catch (SupabaseApiException $exception) {
            return $this->render('marketplace/index.html.twig', [
                'error' => $exception->getMessage(),
                'result' => null,
                'type_counts' => $this->emptyTypeCounts(),
                'mautic_versions' => [],
                'mautic_version_labels' => [],
                'filters' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'orderby' => $orderBy,
                    'orderdir' => $orderDir,
                    'type' => $type,
                    'query' => $query,
                    'mautic' => $mauticVersions,
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
            'filters' => [
                'limit' => $limit,
                'offset' => $offset,
                'orderby' => $orderBy,
                'orderdir' => $orderDir,
                'type' => $type,
                'query' => $query,
                'mautic' => $mauticVersions,
                'date_range' => $dateRange,
                'popularity' => $popularity,
            ],
            'auth0_domain' => $this->auth0Domain,
            'auth0_client_id' => $this->auth0ClientId,
        ]);
    }

    public function detail(string $package): Response
    {
        try {
            $detail = $this->apiClient->getPackage($package);
        } catch (SupabaseApiException $exception) {
            return $this->render('marketplace/detail.html.twig', [
                'error' => $exception->getMessage(),
                'package' => null,
                'name' => $package,
            ], new Response('', Response::HTTP_BAD_GATEWAY));
        }

        if (!$detail instanceof \App\Marketplace\Dto\PackageDetail) {
            throw $this->createNotFoundException('Package not found.');
        }

        return $this->render('marketplace/detail.html.twig', [
            'error' => null,
            'package' => $detail,
            'name' => $package,
            'auth0_domain' => $this->auth0Domain,
            'auth0_client_id' => $this->auth0ClientId,
        ]);
    }

    private function toInt(mixed $value, int $default): int
    {
        if (null === $value || '' === $value) {
            return $default;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return array<string, int>
     */
    private function buildTypeCounts(?string $query, array $mauticVersions, ?string $dateRange, ?string $popularity): array
    {
        $baseResult = $this->apiClient->listPackages(
            1,
            0,
            'downloads',
            'desc',
            null,
            $query,
            $mauticVersions,
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
}
