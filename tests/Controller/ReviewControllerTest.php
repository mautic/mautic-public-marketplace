<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Auth0\Auth0User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReviewControllerTest extends WebTestCase
{
    public function testAnonymousSubmitRedirectsToLogin(): void
    {
        $client = self::createClient();
        $client->request('POST', '/package/vendor/package/review', [
            'rating' => '5',
            'review' => 'Great plugin!',
            '_token' => 'invalid',
        ]);

        self::assertResponseRedirects('/auth/login?returnTo=/');
    }

    public function testAuthenticatedSubmitSucceeds(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $token = $this->csrfTokenFromDetailPage($client, 'vendor/package');

        $client->request('POST', '/package/vendor/package/review', [
            'rating' => '5',
            'review' => 'Great plugin!',
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/package/vendor/package');

        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Review submitted successfully.');
    }

    public function testAuthenticatedSubmitWithInvalidRatingShowsInlineError(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $token = $this->csrfTokenFromDetailPage($client, 'vendor/package');

        $client->request('POST', '/package/vendor/package/review', [
            'rating' => '0',
            'review' => 'Great plugin!',
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('body', 'Rating must be between 1 and 5.');
    }

    public function testAuthenticatedSubmitWithEmptyReviewShowsInlineError(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $token = $this->csrfTokenFromDetailPage($client, 'vendor/package');

        $client->request('POST', '/package/vendor/package/review', [
            'rating' => '4',
            'review' => '   ',
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('body', 'Review text is required.');
    }

    public function testCsrfFailureIsRejected(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $client->request('POST', '/package/vendor/package/review', [
            'rating' => '5',
            'review' => 'Great plugin!',
            '_token' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    private function csrfTokenFromDetailPage(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $package): string
    {
        $client->request('GET', '/package/'.$package);

        self::assertResponseIsSuccessful();

        return (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
    }
}
