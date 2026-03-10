<?php

declare(strict_types=1);

namespace App\Controller;

use App\Marketplace\Exception\MarketplaceApiException;
use App\Marketplace\MarketplaceApiClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MarketplaceController extends AbstractController
{
    public function __construct(
        private readonly MarketplaceApiClient $apiClient,
    ) {
    }

    public function index(Request $request): Response
    {
        $limit = $this->toInt($request->query->get('limit'), 10);
        $offset = $this->toInt($request->query->get('offset'), 0);
        $orderBy = (string) $request->query->get('orderby', 'downloads');
        $orderDir = (string) $request->query->get('orderdir', 'desc');
        $type = $request->query->get('type');
        $query = $request->query->get('query');
        $mautic = $request->query->get('mautic');
        $dateRange = $request->query->get('date_range');
        $maintainer = $request->query->get('maintainer');
        $popularity = $request->query->get('popularity');

        try {
            $result = $this->apiClient->listPackages(
                $limit,
                $offset,
                $orderBy,
                $orderDir,
                \is_string($type) ? $type : null,
                \is_string($query) ? $query : null,
                \is_string($mautic) ? $mautic : null,
                \is_string($dateRange) ? $dateRange : null,
                \is_string($maintainer) ? $maintainer : null,
                \is_string($popularity) ? $popularity : null,
            );
        } catch (MarketplaceApiException $exception) {
            return $this->render('marketplace/index.html.twig', [
                'error' => $exception->getMessage(),
                'result' => null,
                'filters' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'orderby' => $orderBy,
                    'orderdir' => $orderDir,
                    'type' => $type,
                    'query' => $query,
                    'mautic' => $mautic,
                    'date_range' => $dateRange,
                    'maintainer' => $maintainer,
                    'popularity' => $popularity,
                ],
            ], new Response('', Response::HTTP_BAD_GATEWAY));
        }

        return $this->render('marketplace/index.html.twig', [
            'error' => null,
            'result' => $result,
            'filters' => [
                'limit' => $limit,
                'offset' => $offset,
                'orderby' => $orderBy,
                'orderdir' => $orderDir,
                'type' => $type,
                'query' => $query,
                'mautic' => $mautic,
                'date_range' => $dateRange,
                'maintainer' => $maintainer,
                'popularity' => $popularity,
            ],
        ]);
    }

    public function detail(string $package): Response
    {
        try {
            $detail = $this->apiClient->getPackage($package);
        } catch (MarketplaceApiException $exception) {
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
        ]);
    }

    private function toInt(mixed $value, int $default): int
    {
        if (null === $value || '' === $value) {
            return $default;
        }

        return is_numeric($value) ? (int) $value : $default;
    }
}
