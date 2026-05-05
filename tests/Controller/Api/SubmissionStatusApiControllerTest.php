<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Auth0\Auth0User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SubmissionStatusApiControllerTest extends WebTestCase
{
    public function testAnonymousIsRedirected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/package/mautic/example-plugin/submission-status');

        self::assertResponseRedirects();
    }

    public function testReturnsStatusForOwner(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/api/package/mautic/example-plugin/submission-status');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('mautic/example-plugin', $data['package']);
        self::assertSame('published', $data['status']);
    }

    public function testReturnsForbiddenForNonOwner(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/api/package/mautic/zebra-theme/submission-status');

        self::assertResponseStatusCodeSame(403);
    }

    public function testReturnsNotFoundForUnknownPackage(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('GET', '/api/package/mautic/does-not-exist/submission-status');

        self::assertResponseStatusCodeSame(404);
    }
}
