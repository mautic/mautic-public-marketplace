<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MarketplaceControllerTest extends WebTestCase
{
    public function testHomepageLoads(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Marketplace');
    }

    public function testFilteringByTypeAndMauticVersion(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?type=mautic-theme&mautic=^4.4');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Marketplace');
        self::assertSelectorTextContains('table', 'Zebra Theme');
        self::assertSelectorTextNotContains('table', 'Beta Resource');
        self::assertSelectorTextNotContains('table', 'Alpha Plugin');
    }

    public function testSortingByNameAsc(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?orderby=name&orderdir=asc');

        self::assertResponseIsSuccessful();
        $rows = $client->getCrawler()->filter('tbody tr td:first-child a');
        self::assertGreaterThanOrEqual(2, $rows->count());
        self::assertSame('Alpha Plugin', trim($rows->first()->text()));
    }

    public function testSortingByDownloadsDesc(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?orderby=downloads&orderdir=desc');

        self::assertResponseIsSuccessful();
        $rows = $client->getCrawler()->filter('tbody tr');
        self::assertGreaterThanOrEqual(1, $rows->count());
        self::assertStringContainsString('Zebra Theme', $rows->first()->text());
    }

    public function testFilteringByResourceType(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?type=mautic-resource');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Beta Resource');
        self::assertSelectorTextNotContains('table', 'Alpha Plugin');
        self::assertSelectorTextNotContains('table', 'Zebra Theme');
    }

    public function testDetailPageLoads(): void
    {
        $client = self::createClient();
        $client->request('GET', '/package/mautic/alpha-plugin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Alpha Plugin');
        self::assertSelectorTextContains('body', 'Alpha plugin for sorting.');
    }
}
