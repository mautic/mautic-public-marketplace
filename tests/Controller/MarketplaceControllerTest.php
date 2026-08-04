<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Auth0\Auth0User;
use App\Marketplace\PackageImageUploader;
use App\Marketplace\PackageZipUploader;
use App\Tests\Mock\SupabaseMockHttpClient;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MarketplaceControllerTest extends WebTestCase
{
    private function packageCardsLinks(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): \Symfony\Component\DomCrawler\Crawler
    {
        return $client->getCrawler()->filter('.card-group .card-group__item > a.tile--clickable');
    }

    /**
     * @return list<string>
     */
    private function packageCardTitles(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        return $this->packageCardsLinks($client)
            ->filter('.card-heading')
            ->each(static fn ($node): string => trim($node->text()));
    }

    public function testHomepageLoads(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Marketing Automation, Made Better.');
        self::assertPageTitleContains('Mautic Marketplace');
    }

    public function testPackageDownloadRedirectsToArchive(): void
    {
        $client = self::createClient();
        $client->request('GET', '/package/mautic/example-plugin/download');

        self::assertResponseRedirects('https://storage.example.test/package-media/dist/mautic_example-plugin/1.0.0.zip');
    }

    public function testPackageDownloadRecordsHistoryForAuthenticatedUsers(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/package/mautic/example-plugin/download');

        self::assertResponseRedirects('https://storage.example.test/package-media/dist/mautic_example-plugin/1.0.0.zip');

        $recorded = self::getContainer()->get(SupabaseMockHttpClient::class)->recordedDownloads;
        self::assertCount(1, $recorded);
        self::assertSame('auth0|test123', $recorded[0]['auth0_user_id']);
        self::assertSame('mautic/example-plugin', $recorded[0]['package_name']);
        self::assertSame('1.0.0', $recorded[0]['package_version']);
    }

    public function testPackageDownloadRecordsHistoryForAnonymousUsers(): void
    {
        $client = self::createClient();
        $client->request('GET', '/package/mautic/example-plugin/download');

        self::assertResponseRedirects('https://storage.example.test/package-media/dist/mautic_example-plugin/1.0.0.zip');

        $recorded = self::getContainer()->get(SupabaseMockHttpClient::class)->recordedDownloads;
        self::assertCount(1, $recorded);
        self::assertNull($recorded[0]['auth0_user_id']);
        self::assertSame('mautic/example-plugin', $recorded[0]['package_name']);
    }

    public function testPackageDownloadBundlesBannerAndGalleryImages(): void
    {
        $client = self::createClient();
        $client->request('GET', '/package/mautic/welcome-campaign/download');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/zip');
        self::assertStringContainsString(
            'Welcome Campaign 1.0.1.zip',
            (string) $client->getResponse()->headers->get('content-disposition'),
        );

        $names = $this->zipEntryNames($client->getInternalResponse()->getContent());

        // Images live under assets/ so the archive keeps the Mautic export layout
        // and the import flow restores them into the media directory.
        self::assertContains('entity_data.json', $names);
        self::assertContains('composer.json', $names);
        self::assertContains('assets/banner.png', $names);
        self::assertContains('assets/gallery/image_1.png', $names);
        self::assertContains('assets/gallery/image_1.alt.txt', $names);
    }

    public function testPackageDownloadServesTheNewestVersion(): void
    {
        $client = self::createClient();
        $client->request('GET', '/package/mautic/welcome-campaign/download');

        self::assertResponseIsSuccessful();

        // The mock lists 1.0.0 before 1.0.1; the newest version must win regardless.
        $recorded = self::getContainer()->get(SupabaseMockHttpClient::class)->recordedDownloads;
        self::assertCount(1, $recorded);
        self::assertSame('1.0.1', $recorded[0]['package_version']);
    }

    /**
     * @return list<string>
     */
    private function zipEntryNames(string $zipContents): array
    {
        $tmpPath = (string) tempnam(sys_get_temp_dir(), 'test-download-');
        file_put_contents($tmpPath, $zipContents);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmpPath), 'The downloaded response is not a readable ZIP archive.');

        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();
        unlink($tmpPath);

        return $names;
    }

    public function testDownloadButtonLinksToDownloadRoute(): void
    {
        $client = self::createClient();
        $client->request('GET', '/package/mautic/example-plugin');

        self::assertResponseIsSuccessful();
        $href = $client->getCrawler()->filter('#package-cta')->attr('href');
        self::assertSame('/package/mautic/example-plugin/download', $href);
    }

    public function testPackageUploadPageRedirectsAnonymousUsers(): void
    {
        $client = self::createClient();
        $client->request('GET', '/upload/package');

        self::assertResponseRedirects('/auth/login?returnTo=/upload/package');
    }

    public function testPackageUploadPageLoadsForAuthenticatedUsers(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/upload/package');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Upload a package');
        self::assertSelectorExists('form.needs-validation[novalidate]');
        self::assertSelectorExists('#package-zip[required][data-validation-field]');
        self::assertPageTitleContains('Upload package');
    }

    /**
     * The wizard reads its client-side limits off these attributes, so they have to carry the
     * same numbers the server enforces — otherwise the two drift apart silently.
     */
    public function testUploadInputsCarryTheServerSideSizeLimits(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $client->request('GET', '/upload/package');
        self::assertSame(
            (string) PackageZipUploader::MAX_BYTES,
            $client->getCrawler()->filter('#package-zip')->attr('data-max-bytes'),
        );

        $client->request('GET', '/upload/package?step=3');
        $crawler = $client->getCrawler();

        self::assertSame(
            (string) PackageImageUploader::MAX_BYTES,
            $crawler->filter('#package-banner-image')->attr('data-max-bytes'),
        );
        self::assertSame(
            (string) PackageImageUploader::MAX_BYTES,
            $crawler->filter('#package-gallery-images')->attr('data-max-bytes'),
        );
    }

    public function testPackageUploadBasicsStepRendersValidationAttributes(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/upload/package?step=2');
        $crawler = $client->getCrawler();

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form.needs-validation[novalidate]');
        self::assertSelectorExists('#package-name[required][data-validation-field]');
        self::assertStringContainsString('notBlank', (string) $crawler->filter('#package-name')->attr('data-validation-rules'));
        self::assertSelectorExists('#package-version[required][pattern][data-validation-field]');
        self::assertSelectorExists('#package-headline[required][maxlength="60"][data-validation-field]');
    }

    public function testPackageUploadDetailsStepRendersValidationAttributes(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/upload/package?step=3');
        $crawler = $client->getCrawler();

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form.needs-validation[novalidate]');
        self::assertSelectorExists('#package-description[required][minlength="150"][data-validation-field]');
        self::assertSelectorExists('#package-languages[required][multiple][data-validation-field]');
        self::assertSelectorExists('fieldset[data-validation-name="works_with[]"][data-validation-required="true"]');
        self::assertStringContainsString('fileTypes', (string) $crawler->filter('#package-banner-image')->attr('data-validation-rules'));
        self::assertStringContainsString('maxFiles', (string) $crawler->filter('#package-gallery-images')->attr('data-validation-rules'));
        self::assertStringContainsString('requiresFileAltText', (string) $crawler->filter('#package-gallery-alt-0')->attr('data-validation-rules'));
    }

    public function testPackageUploadPricingLegalStepRendersValidationAttributes(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/upload/package?step=4');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form.needs-validation[novalidate]');
        self::assertSelectorExists('#package-license-type[required][data-validation-field]');
        self::assertSelectorExists('#package-price-control[min="0"][step="0.01"][data-validation-field]');
        self::assertSelectorExists('fieldset[data-validation-name="legal_confirmation[]"][data-validation-required="true"]');
    }

    public function testBrowsePageLoads(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h1');
        self::assertSelectorExists('input[name="query"][placeholder="Search packages, maintainers, or tags"]');
        self::assertSelectorExists('a[href="/auth/login?returnTo=/browse"]');
    }

    public function testFilteringByTypeAndMauticVersion(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?type=mautic-theme&mautic[]=%5E4.4');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-group', 'Zebra Theme');
        self::assertSelectorTextNotContains('.card-group', 'Alpha Plugin');
    }

    public function testMauticVersionFilterRendersCheckboxOptionsAndShowAllToggle(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse');

        self::assertResponseIsSuccessful();

        $labels = $client->getCrawler()->filter('input[name="mautic[]"] + label')
            ->each(static fn ($node): string => trim($node->text()));

        self::assertContains('v4.2+', $labels);
        self::assertContains('v4.3+', $labels);
        self::assertContains('v4.4+', $labels);
        self::assertContains('v5.0+', $labels);
        self::assertContains('v5.1+', $labels);
        self::assertContains('v5.2+', $labels);
        self::assertContains('v5.3+', $labels);
        self::assertSelectorTextContains('[data-view-all-toggle]', 'View all');
    }

    public function testLanguageFilterRendersOnlyLanguagesPresentInMatchingResults(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse');

        self::assertResponseIsSuccessful();

        $labels = $client->getCrawler()->filter('input[name="language[]"] + label')
            ->each(static fn ($node): string => trim($node->text()));

        self::assertSame(['Dutch', 'English'], $labels);
        self::assertNotContains('French', $labels);
    }

    public function testLanguageFilterNarrowsOptionsToCurrentFilteredResultSet(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?type=mautic-plugin');

        self::assertResponseIsSuccessful();

        $labels = $client->getCrawler()->filter('input[name="language[]"] + label')
            ->each(static fn ($node): string => trim($node->text()));

        self::assertSame(['English'], $labels);
        self::assertNotContains('Dutch', $labels);
    }

    public function testFilteringByMultipleMauticVersions(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?mautic[]=%5E4.2&mautic[]=%5E4.4');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-group', 'Welcome Campaign');
        self::assertSelectorTextContains('.card-group', 'Zebra Theme');
        self::assertSelectorTextNotContains('.card-group', 'Example Plugin');
        self::assertSelectorTextNotContains('.card-group', 'Alpha Plugin');
    }

    public function testFilteringByLanguage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?language[]=english');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-group', 'Example Plugin');
        self::assertSelectorTextContains('.card-group', 'Alpha Plugin');
        self::assertSelectorTextNotContains('.card-group', 'Zebra Theme');
        self::assertSelectorTextNotContains('.card-group', 'Welcome Campaign');
    }

    public function testFilteringByMultipleLanguages(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?language[]=english&language[]=dutch');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-group', 'Example Plugin');
        self::assertSelectorTextContains('.card-group', 'Alpha Plugin');
        self::assertSelectorTextContains('.card-group', 'Zebra Theme');
        self::assertSelectorTextContains('.card-group', 'Welcome Campaign');
    }

    public function testLanguageFilterKeepsSiblingOptionsWhenSelected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?query=escopecz&language[]=english');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-group', 'Example Plugin');
        self::assertSelectorTextNotContains('.card-group', 'Zebra Theme');

        $labels = $client->getCrawler()->filter('input[name="language[]"] + label')
            ->each(static fn ($node): string => trim($node->text()));

        self::assertSame(['Dutch', 'English'], $labels);
    }

    public function testLanguageFilterIsHiddenWhenNoMatchingResultsExist(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?query=no-such-package');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="language[]"]');
    }

    public function testRatingFilterRendersThresholdOptions(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse');

        self::assertResponseIsSuccessful();

        $labels = $client->getCrawler()->filter('input[name="rating"] + label')
            ->each(static fn ($node): string => trim($node->text()));

        self::assertContains('5 stars', $labels);
        self::assertContains('At least 4 stars', $labels);
        self::assertContains('At least 3 stars', $labels);
        self::assertContains('At least 2 stars', $labels);
        self::assertContains('At least 1 star', $labels);
        self::assertContains('Not rated yet', $labels);
    }

    public function testAccountFilterGroupIsHiddenForAnonymousUsers(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Tied to my account');
        self::assertSelectorNotExists('input[name="things_i_rated[]"]');
        self::assertSelectorNotExists('input[name="my_submitted_packages[]"]');
    }

    public function testAccountFilterGroupRendersForAuthenticatedUsers(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/browse');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Tied to my account');
        self::assertSelectorTextContains('body', 'Packages rated by me');
        self::assertSelectorTextContains('body', 'My submitted packages');
        self::assertSelectorExists('input[name="things_i_rated[]"][value="1"]');
        self::assertSelectorExists('input[name="my_submitted_packages[]"][value="1"]');
        self::assertSelectorNotExists('input[name="rated_by"]');
    }

    public function testFilteringByMinimumRating(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?rating=4');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-group', 'Example Plugin');
        self::assertSelectorTextContains('.card-group', 'Zebra Theme');
        self::assertSelectorTextNotContains('.card-group', 'Alpha Plugin');
        self::assertSelectorTextNotContains('.card-group', 'Welcome Campaign');
    }

    public function testFiveStarFilterIncludesRatingsThatRoundUp(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?rating=5');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-group', 'Example Plugin');
        self::assertSelectorTextNotContains('.card-group', 'Zebra Theme');
        self::assertSelectorTextNotContains('.card-group', 'Alpha Plugin');
        self::assertSelectorTextNotContains('.card-group', 'Welcome Campaign');
    }

    public function testFilteringByNotRatedYet(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?rating=unrated');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-group', 'Welcome Campaign');
        self::assertSelectorTextNotContains('.card-group', 'Example Plugin');
        self::assertSelectorTextNotContains('.card-group', 'Zebra Theme');
        self::assertSelectorTextNotContains('.card-group', 'Alpha Plugin');
    }

    public function testFilteringByThingsIRated(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/browse?things_i_rated%5B%5D=1&rated_by=auth0%7Cother');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-group', 'Example Plugin');
        self::assertSelectorTextContains('.card-group', 'Zebra Theme');
        self::assertSelectorTextNotContains('.card-group', 'Alpha Plugin');
        self::assertSelectorTextNotContains('.card-group', 'Welcome Campaign');
    }

    public function testFilteringByMySubmittedPackages(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/browse?my_submitted_packages%5B%5D=1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-group', 'Example Plugin');
        self::assertSelectorTextContains('.card-group', 'Welcome Campaign');
        self::assertSelectorTextNotContains('.card-group', 'Zebra Theme');
        self::assertSelectorTextNotContains('.card-group', 'Alpha Plugin');
    }

    public function testSortingByNameAsc(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?orderby=name&orderdir=asc');

        self::assertResponseIsSuccessful();
        $titles = $this->packageCardTitles($client);
        self::assertGreaterThanOrEqual(2, \count($titles));
        self::assertSame('Alpha Plugin', $titles[0]);
    }

    public function testSortingByDownloadsDesc(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?orderby=downloads&orderdir=desc');

        self::assertResponseIsSuccessful();
        $titles = $this->packageCardTitles($client);
        self::assertGreaterThanOrEqual(1, \count($titles));
        self::assertSame('Zebra Theme', $titles[0]);
    }

    public function testDetailPageLoads(): void
    {
        $client = self::createClient();
        $client->request('GET', '/package/mautic/alpha-plugin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Alpha Plugin');
        self::assertSelectorTextContains('body', 'Alpha plugin for sorting.');
        self::assertSelectorExists('a#tag-v4-3[href$="/browse?mautic%5B0%5D=%5E4.3"]');
        self::assertSelectorExists('a#tag-v5-0[href$="/browse?mautic%5B0%5D=%5E5.0"]');
        self::assertSelectorExists('a#tag-v4-3 span[data-bs-toggle="tooltip"][title="Supported Mautic version"]');
        self::assertSelectorExists('a#tag-v5-0 span[data-bs-toggle="tooltip"][title="Supported Mautic version"]');
        self::assertSelectorTextContains('body', 'v4.3+');
        self::assertSelectorTextContains('body', 'v5.0+');
        self::assertSelectorTextContains('time', '2 months ago');
        self::assertSelectorExists(\sprintf('i[title="%s"]', (new \DateTimeImmutable('first day of this month -2 months'))->format('Y-m-d')));
        self::assertSelectorExists('a[href="/auth/login?returnTo=/package/mautic/alpha-plugin"]');
    }

    public function testAuthenticatedDetailPageShowsAccountMenuAndReviewForm(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/package/mautic/alpha-plugin');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/package/mautic/alpha-plugin/review"]');
        self::assertSelectorExists('a[href="/profile"]');
        self::assertSelectorExists('a[href="/auth/logout"]');
    }

    public function testReviewFormRendersValidationMarkup(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/package/mautic/alpha-plugin');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form#review-form.needs-validation[novalidate]');
        self::assertSelectorExists('#review-help[data-validation-help]');
        self::assertSelectorExists('#review-feedback[data-validation-feedback]');
        self::assertSelectorExists('#rating-feedback[data-validation-feedback]');

        $reviewField = $client->getCrawler()->filter('#review');

        self::assertSame('review-help', $reviewField->attr('aria-describedby'));
        self::assertSame('review-feedback', $reviewField->attr('data-validation-feedback-id'));
    }

    public function testFilterByResourceType(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?type=mautic-resource');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-group', 'Welcome Campaign');
        self::assertSelectorTextNotContains('.card-group', 'Example Plugin');
        self::assertSelectorTextNotContains('.card-group', 'Zebra Theme');
    }

    public function testTypeFilterRendersRadioOptions(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse');

        self::assertResponseIsSuccessful();
        $labels = $client->getCrawler()->filter('input[name="type"] + label')
            ->each(static fn ($node): string => trim($node->text()));
        $allTypesValue = $client->getCrawler()->filter('input[name="type"]')->first()->attr('value');

        self::assertContains('Campaign (1)', $labels);
        self::assertContains('Plugin (2)', $labels);
        self::assertContains('Theme (1)', $labels);
        self::assertSame('', $allTypesValue);
    }

    public function testGroupedFilterControlsRenderSharedValidationFeedback(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse');

        self::assertResponseIsSuccessful();

        $typeGroup = $client->getCrawler()->filter('fieldset[data-validation-group][data-validation-name="type"]');

        self::assertSame(1, $typeGroup->count());
        self::assertSame(1, $typeGroup->filter('[data-validation-feedback]')->count());
    }

    public function testTypeCountsAreCustomizedBySearch(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?query=alpha');

        self::assertResponseIsSuccessful();
        $labels = $client->getCrawler()->filter('input[name="type"] + label')
            ->each(static fn ($node): string => trim($node->text()));

        self::assertContains('All types (1)', $labels);
        self::assertContains('Plugin (1)', $labels);
        self::assertContains('Theme (0)', $labels);
        self::assertContains('Campaign (0)', $labels);
    }

    public function testFilterByPluginTypeExcludesThemesAndResources(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?type=mautic-plugin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-group', 'Example Plugin');
        self::assertSelectorTextNotContains('.card-group', 'Zebra Theme');
        self::assertSelectorTextNotContains('.card-group', 'Welcome Campaign');
    }

    public function testSearchByMaintainer(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?query=rcheesley');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-group', 'Alpha Plugin');
        self::assertSelectorTextContains('.card-group', 'Welcome Campaign');
        self::assertSelectorTextNotContains('.card-group', 'Zebra Theme');
    }

    public function testSearchByTag(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?query=automation');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-group', 'Welcome Campaign');
        self::assertSelectorTextNotContains('.card-group', 'Example Plugin');
        self::assertSelectorTextNotContains('.card-group', 'Alpha Plugin');
        self::assertSelectorTextNotContains('.card-group', 'Zebra Theme');
    }

    public function testPopularityMostPopular(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?popularity=most_popular');

        self::assertResponseIsSuccessful();
        $titles = $this->packageCardTitles($client);
        self::assertGreaterThanOrEqual(2, \count($titles));
        self::assertSame('Zebra Theme', $titles[0]);
    }

    public function testPopularityNewest(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?popularity=newest');

        self::assertResponseIsSuccessful();
        $titles = $this->packageCardTitles($client);
        self::assertGreaterThanOrEqual(2, \count($titles));
        // Example Plugin is 5 days old, the most recent
        self::assertSame('Example Plugin', $titles[0]);
    }

    public function testDateRangeFilter30Days(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?date_range=30d');

        self::assertResponseIsSuccessful();
        // Example Plugin (5 days) and Welcome Campaign (10 days) are within 30 days
        self::assertSelectorTextContains('.card-group', 'Example Plugin');
        self::assertSelectorTextContains('.card-group', 'Welcome Campaign');
        // Alpha Plugin (60 days) and Zebra Theme (200 days) are outside 30 days
        self::assertSelectorTextNotContains('.card-group', 'Alpha Plugin');
        self::assertSelectorTextNotContains('.card-group', 'Zebra Theme');
    }

    public function testDateRangeFilter7Days(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?date_range=7d');

        self::assertResponseIsSuccessful();
        // Only Example Plugin (5 days old) is within 7 days
        self::assertSelectorTextContains('.card-group', 'Example Plugin');
        self::assertSelectorTextNotContains('.card-group', 'Welcome Campaign');
        self::assertSelectorTextNotContains('.card-group', 'Alpha Plugin');
    }

    public function testSearchQuery(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?query=zebra');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-group', 'Zebra Theme');
        self::assertSelectorTextNotContains('.card-group', 'Example Plugin');
    }

    public function testPaginationLimitOffset(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?limit=2&offset=0&orderby=name&orderdir=asc');

        self::assertResponseIsSuccessful();
        $cards = $this->packageCardsLinks($client);
        self::assertSame(2, $cards->count());

        // Second page
        $client->request('GET', '/browse?limit=2&offset=2&orderby=name&orderdir=asc');
        self::assertResponseIsSuccessful();
        $cards = $this->packageCardsLinks($client);
        self::assertGreaterThanOrEqual(1, $cards->count());
    }

    public function testPaginationShowsControls(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?limit=2');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.pagination-nav');
        self::assertSelectorTextContains('.pagination-nav__status', 'Page 1');
    }

    public function testCombinedFilters(): void
    {
        $client = self::createClient();
        $client->request('GET', '/browse?type=mautic-plugin&query=escopecz');

        self::assertResponseIsSuccessful();
        // escopecz maintains example-plugin (plugin), zebra-theme (theme)
        // Only the plugin should show with type filter
        self::assertSelectorTextContains('.card-group', 'Example Plugin');
        self::assertSelectorTextNotContains('.card-group', 'Zebra Theme');
    }
}
