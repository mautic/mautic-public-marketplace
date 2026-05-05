<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MauticVersionsApiControllerTest extends WebTestCase
{
    public function testListReturnsVersions(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/mautic-versions');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data['versions']);
        self::assertNotEmpty($data['versions']);
        self::assertContains('5.2', $data['versions']);
    }
}
