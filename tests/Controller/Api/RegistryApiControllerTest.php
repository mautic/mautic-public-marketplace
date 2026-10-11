<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Supabase\SupabaseClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\HttpClient;

final class RegistryApiControllerTest extends WebTestCase
{
    /**
     * A Mautic instance calls this with no credentials of its own, so it has to work while
     * logged out. That is the whole point of the endpoint.
     */
    public function testPackageListIsPublic(): void
    {
        $client = $this->createRegistryClient();
        $client->request('GET', '/api/registry/v1/packages');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $payload = $this->decode($client);
        self::assertArrayHasKey('total', $payload);
        self::assertArrayHasKey('results', $payload);
        self::assertIsInt($payload['total']);
        self::assertIsArray($payload['results']);
    }

    public function testPackageDetailIsPublic(): void
    {
        $client = $this->createRegistryClient();
        $client->request('GET', '/api/registry/v1/packages/mautic/example-plugin');

        self::assertResponseIsSuccessful();

        $payload = $this->decode($client);
        self::assertSame('mautic/example-plugin', $payload['package']['name']);
    }

    /**
     * Left unbounded, a caller could ask for the whole table in one request.
     */
    public function testLimitIsCapped(): void
    {
        $client = $this->createRegistryClient();
        $client->request('GET', '/api/registry/v1/packages?limit=5000');

        self::assertResponseIsSuccessful();
        self::assertLessThanOrEqual(100, \count($this->decode($client)['results']));
    }

    #[DataProvider('packageTypes')]
    public function testPackageTypeFiltersResultsAndTotal(string $type): void
    {
        $client = $this->createRegistryClient();
        $client->request('GET', '/api/registry/v1/packages?limit=100');
        self::assertResponseIsSuccessful();
        $all = $this->decode($client);
        self::assertCount($all['total'], $all['results']);
        $expected = array_values(array_filter($all['results'], static fn (array $row): bool => $row['type'] === $type));
        self::assertNotEmpty($expected, 'Fixtures must contain the requested package type.');
        self::assertLessThan($all['total'], \count($expected), 'Fixtures must also contain other types.');

        $client->request('GET', '/api/registry/v1/packages', ['type' => $type, 'limit' => 100]);
        self::assertResponseIsSuccessful();
        $filtered = $this->decode($client);
        self::assertSame(\count($expected), $filtered['total']);
        self::assertSame(array_column($expected, 'name'), array_column($filtered['results'], 'name'));
        foreach ($filtered['results'] as $row) {
            self::assertSame($type, $row['type']);
        }

        // The total counts every matching package, even beyond the requested page.
        $client->request('GET', '/api/registry/v1/packages', ['type' => $type, 'limit' => 1, 'page' => 2]);
        self::assertResponseIsSuccessful();
        $page = $this->decode($client);
        self::assertSame($filtered['total'], $page['total']);
        self::assertSame(array_slice(array_column($expected, 'name'), 1, 1), array_column($page['results'], 'name'));
    }

    /** @return iterable<string, array{string}> */
    public static function packageTypes(): iterable
    {
        yield 'plugins' => ['mautic-plugin'];
        yield 'themes' => ['mautic-theme'];
        yield 'resources' => ['mautic-resource'];
    }

    public function testPackageTypeCombinesWithSearch(): void
    {
        $client = $this->createRegistryClient();
        $client->request('GET', '/api/registry/v1/packages', ['query' => 'zebra', 'type' => ' mautic-theme ']);
        self::assertResponseIsSuccessful();
        $payload = $this->decode($client);
        self::assertSame(1, $payload['total']);
        self::assertSame(['mautic/zebra-theme'], array_column($payload['results'], 'name'));
        self::assertSame('mautic-theme', $payload['results'][0]['type']);

        $client->request('GET', '/api/registry/v1/packages', ['query' => 'zebra', 'type' => 'mautic-plugin']);
        self::assertResponseIsSuccessful();
        self::assertSame(['total' => 0, 'results' => []], $this->decode($client));
    }

    public function testEmptyTypeKeepsAllPackagesAndUnknownTypeReturnsNone(): void
    {
        $client = $this->createRegistryClient();
        $client->request('GET', '/api/registry/v1/packages');
        self::assertResponseIsSuccessful();
        $all = $this->decode($client);
        foreach (['', '   '] as $type) {
            $client->request('GET', '/api/registry/v1/packages', ['type' => $type]);
            self::assertResponseIsSuccessful();
            $payload = $this->decode($client);
            self::assertSame($all['total'], $payload['total']);
            self::assertSame(array_column($all['results'], 'name'), array_column($payload['results'], 'name'));
        }
        $client->request('GET', '/api/registry/v1/packages', ['type' => 'unknown-package-type']);
        self::assertResponseIsSuccessful();
        self::assertSame(['total' => 0, 'results' => []], $this->decode($client));
    }

    private function createRegistryClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->disableReboot();

        // CI repeats these requests through the real Supabase HTTP client and database.
        $baseUri = getenv('REGISTRY_API_INTEGRATION_URL');
        if (false !== $baseUri && '' !== $baseUri) {
            self::getContainer()->set(SupabaseClient::class, new SupabaseClient(
                HttpClient::create(),
                $baseUri,
                (string) ($_SERVER['SUPABASE_ANON_KEY'] ?? getenv('SUPABASE_ANON_KEY')),
                '',
            ));
        }

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
