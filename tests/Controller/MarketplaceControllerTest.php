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

    public function testDetailPageLoads(): void
    {
        $client = self::createClient();
        $client->request('GET', '/package/mautic/alpha-plugin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Alpha Plugin');
        self::assertSelectorTextContains('body', 'Alpha plugin for sorting.');
    }

    public function testFilterByResourceType(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?type=mautic-resource');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Welcome Campaign');
        self::assertSelectorTextNotContains('table', 'Example Plugin');
        self::assertSelectorTextNotContains('table', 'Zebra Theme');
    }

    public function testResourceTypeOptionInDropdown(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $options = $client->getCrawler()->filter('select[name="type"] option');
        $values = $options->each(static fn ($node) => $node->attr('value'));
        self::assertContains('mautic-resource', $values);
    }

    public function testFilterByPluginTypeExcludesThemesAndResources(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?type=mautic-plugin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Example Plugin');
        self::assertSelectorTextNotContains('table', 'Zebra Theme');
        self::assertSelectorTextNotContains('table', 'Welcome Campaign');
    }

    public function testSearchByMaintainer(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?query=rcheesley');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Alpha Plugin');
        self::assertSelectorTextContains('table', 'Welcome Campaign');
        self::assertSelectorTextNotContains('table', 'Zebra Theme');
    }

    public function testPopularityMostPopular(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?popularity=most_popular');

        self::assertResponseIsSuccessful();
        $rows = $client->getCrawler()->filter('tbody tr td:first-child a');
        self::assertGreaterThanOrEqual(2, $rows->count());
        self::assertSame('Zebra Theme', trim($rows->first()->text()));
    }

    public function testPopularityNewest(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?popularity=newest');

        self::assertResponseIsSuccessful();
        $rows = $client->getCrawler()->filter('tbody tr td:first-child a');
        self::assertGreaterThanOrEqual(2, $rows->count());
        // Example Plugin is 5 days old, the most recent
        self::assertSame('Example Plugin', trim($rows->first()->text()));
    }

    public function testDateRangeFilter30Days(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?date_range=30d');

        self::assertResponseIsSuccessful();
        // Example Plugin (5 days) and Welcome Campaign (10 days) are within 30 days
        self::assertSelectorTextContains('table', 'Example Plugin');
        self::assertSelectorTextContains('table', 'Welcome Campaign');
        // Alpha Plugin (60 days) and Zebra Theme (200 days) are outside 30 days
        self::assertSelectorTextNotContains('table', 'Alpha Plugin');
        self::assertSelectorTextNotContains('table', 'Zebra Theme');
    }

    public function testDateRangeFilter7Days(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?date_range=7d');

        self::assertResponseIsSuccessful();
        // Only Example Plugin (5 days old) is within 7 days
        self::assertSelectorTextContains('table', 'Example Plugin');
        self::assertSelectorTextNotContains('table', 'Welcome Campaign');
        self::assertSelectorTextNotContains('table', 'Alpha Plugin');
    }

    public function testSearchQuery(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?query=zebra');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Zebra Theme');
        self::assertSelectorTextNotContains('table', 'Example Plugin');
    }

    public function testPaginationLimitOffset(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?limit=2&offset=0&orderby=name&orderdir=asc');

        self::assertResponseIsSuccessful();
        $rows = $client->getCrawler()->filter('tbody tr');
        self::assertSame(2, $rows->count());

        // Second page
        $client->request('GET', '/?limit=2&offset=2&orderby=name&orderdir=asc');
        self::assertResponseIsSuccessful();
        $rows = $client->getCrawler()->filter('tbody tr');
        self::assertGreaterThanOrEqual(1, $rows->count());
    }

    public function testPaginationShowsControls(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?limit=2');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.pagination');
        self::assertSelectorTextContains('.pagination__meta', 'Page 1');
    }

    public function testCombinedFilters(): void
    {
        $client = self::createClient();
        $client->request('GET', '/?type=mautic-plugin&query=escopecz');

        self::assertResponseIsSuccessful();
        // escopecz maintains example-plugin (plugin), zebra-theme (theme)
        // Only the plugin should show with type filter
        self::assertSelectorTextContains('table', 'Example Plugin');
        self::assertSelectorTextNotContains('table', 'Zebra Theme');
    }
}
