<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Supabase\Exception\SupabaseApiException;
use App\Supabase\SupabaseClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public read API a Mautic instance browses the marketplace through.
 *
 * Mautic used to call Supabase directly, which meant every install shipped a copy of the
 * anon key. Going through here keeps the credential on this side, and gives the two a
 * stable contract instead of one pinned to Supabase's function names.
 */
final class RegistryApiController extends AbstractController
{
    private const DEFAULT_LIMIT = 30;

    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly SupabaseClient $supabaseClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function packages(Request $request): JsonResponse
    {
        $limit = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', self::DEFAULT_LIMIT)));
        $page = max(1, $request->query->getInt('page', 1));

        $params = [
            '_limit' => $limit,
            '_offset' => ($page - 1) * $limit,
        ];

        $query = trim($request->query->getString('query'));
        if ('' !== $query) {
            $params['_query'] = $query;
        }

        try {
            $data = $this->supabaseClient->rpc('/rest/v1/rpc/get_view', $params);
        } catch (SupabaseApiException $e) {
            return $this->unavailable('list packages', $e);
        }

        return $this->json($this->normalizeList(\is_array($data) ? $data : []));
    }

    public function package(string $vendor, string $package): JsonResponse
    {
        try {
            $data = $this->supabaseClient->query('GET', '/rest/v1/rpc/get_pack', [
                'packag_name' => $vendor.'/'.$package,
            ]);
        } catch (SupabaseApiException $e) {
            return $this->unavailable('read package', $e);
        }

        $row = \is_array($data) ? ($data['package'] ?? null) : null;

        if (!\is_array($row) || !isset($row['name'])) {
            return $this->json(['error' => 'Package not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['package' => $row]);
    }

    /**
     * PostgREST returns the row either on its own or wrapped in a single-element list, so pin
     * the shape here rather than leaving every consumer to cope with both.
     *
     * @param array<mixed> $data
     *
     * @return array{total: int, results: list<mixed>}
     */
    private function normalizeList(array $data): array
    {
        $payload = \array_key_exists('results', $data) ? $data : ($data[0] ?? []);

        if (!\is_array($payload)) {
            return ['total' => 0, 'results' => []];
        }

        $results = $payload['results'] ?? [];

        return [
            'total' => (int) ($payload['total'] ?? 0),
            'results' => \is_array($results) ? array_values($results) : [],
        ];
    }

    private function unavailable(string $action, SupabaseApiException $e): JsonResponse
    {
        $this->logger->error(\sprintf('Registry API failed to %s.', $action), ['exception' => $e]);

        return $this->json(
            ['error' => 'The marketplace is temporarily unavailable. Please try again shortly.'],
            Response::HTTP_BAD_GATEWAY,
        );
    }
}
