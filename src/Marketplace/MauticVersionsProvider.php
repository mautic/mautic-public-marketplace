<?php

declare(strict_types=1);

namespace App\Marketplace;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MauticVersionsProvider
{
    private const PACKAGIST_URL = 'https://repo.packagist.org/p2/mautic/core-lib.json';
    private const CACHE_KEY = 'mautic_versions.supported';
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * Used when Packagist is unreachable or returns an unexpected shape, so the
     * upload form keeps working even when the network does not.
     *
     * @var list<string>
     */
    private const FALLBACK_VERSIONS = ['7', '6', '5', '4'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getSupportedVersions(): array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            $versions = $this->fetchFromPackagist();
            if ([] === $versions) {
                $item->expiresAfter(60);

                return self::FALLBACK_VERSIONS;
            }

            return $versions;
        });
    }

    /**
     * @return list<string>
     */
    private function fetchFromPackagist(): array
    {
        try {
            $response = $this->httpClient->request('GET', self::PACKAGIST_URL, [
                'timeout' => 5,
            ]);
            $payload = $response->toArray(false);
        } catch (HttpClientExceptionInterface|\JsonException $e) {
            $this->logger->warning('Could not fetch Mautic versions from Packagist; using fallback list.', [
                'exception' => $e,
            ]);

            return [];
        }

        $entries = $payload['packages']['mautic/core-lib'] ?? null;
        if (!\is_array($entries)) {
            return [];
        }

        $majors = [];
        foreach ($entries as $entry) {
            $version = \is_array($entry) ? ($entry['version_normalized'] ?? $entry['version'] ?? null) : null;
            if (!\is_string($version)) {
                continue;
            }

            if (!preg_match('/^v?(\d+)\./', $version, $matches)) {
                continue;
            }

            $majors[(int) $matches[1]] = true;
        }

        $sorted = array_keys($majors);
        rsort($sorted);

        return array_map(static fn (int $v): string => (string) $v, $sorted);
    }
}
