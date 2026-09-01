<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistryApiControllerTest extends WebTestCase
{
    /**
     * A Mautic instance calls this with no credentials of its own, so it has to work while
     * logged out. That is the whole point of the endpoint.
     */
    public function testPackageListIsPublic(): void
    {
        $client = self::createClient();
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
        $client = self::createClient();
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
        $client = self::createClient();
        $client->request('GET', '/api/registry/v1/packages?limit=5000');

        self::assertResponseIsSuccessful();
        self::assertLessThanOrEqual(100, \count($this->decode($client)['results']));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
