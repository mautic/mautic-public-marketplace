<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProfileControllerTest extends WebTestCase
{
    public function testProfilePageLoads(): void
    {
        $client = self::createClient();
        $client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('My Profile');
    }

    public function testProfilePageContainsRequiredElements(): void
    {
        $client = self::createClient();
        $client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#profile-container');
        self::assertSelectorExists('#profile-loading');
        self::assertSelectorExists('#profile-login');
        self::assertSelectorExists('#profile-content');
    }

    public function testProfilePageHasAuth0DataAttributes(): void
    {
        $client = self::createClient();
        $client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#profile-container[data-api-url]');
        self::assertSelectorExists('#profile-container[data-auth0-domain]');
        self::assertSelectorExists('#profile-container[data-auth0-client-id]');
    }
}
