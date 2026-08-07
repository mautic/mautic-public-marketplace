<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Auth0\Auth0User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class AuthControllerTest extends WebTestCase
{
    public function testLoginRedirectsToAuth0AndStoresPkceState(): void
    {
        $client = self::createClient();
        $client->request('GET', '/auth/login?returnTo=/profile');

        self::assertResponseRedirects();

        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('https://test.auth0.com/authorize', $location);
        self::assertStringContainsString('client_id=test-client-id', $location);
        self::assertStringContainsString('redirect_uri=', $location);
        self::assertStringContainsString('code_challenge=', $location);
        self::assertStringContainsString('state=', $location);

        $pendingLogin = $this->getSessionFromClient($client)->get('auth0_login');

        self::assertIsArray($pendingLogin);
        self::assertSame('/profile', $pendingLogin['return_to']);
        self::assertNotEmpty($pendingLogin['state']);
        self::assertNotEmpty($pendingLogin['nonce']);
        self::assertNotEmpty($pendingLogin['code_verifier']);
    }

    public function testLoginRejectsUnsafeReturnTo(): void
    {
        $client = self::createClient();
        $client->request('GET', '/auth/login?returnTo=https://example.com/evil');

        self::assertResponseRedirects();

        $pendingLogin = $this->getSessionFromClient($client)->get('auth0_login');

        self::assertIsArray($pendingLogin);
        self::assertSame('/', $pendingLogin['return_to']);
    }

    public function testCallbackLogsUserInAndRedirectsBack(): void
    {
        $client = self::createClient();
        $this->seedPendingLogin($client, [
            'state' => 'test-state',
            'nonce' => 'test-nonce',
            'code_verifier' => 'test-code-verifier',
            'return_to' => '/profile',
        ]);

        $client->request('GET', '/auth/callback?code=test-code&state=test-state');

        self::assertResponseRedirects('/profile');

        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Test User');
        self::assertSelectorTextContains('body', 'test@example.com');
    }

    public function testCallbackWithInvalidStateFailsSafely(): void
    {
        $client = self::createClient();
        $this->seedPendingLogin($client, [
            'state' => 'test-state',
            'nonce' => 'test-nonce',
            'code_verifier' => 'test-code-verifier',
            'return_to' => '/profile',
        ]);

        $client->request('GET', '/auth/callback?code=test-code&state=wrong-state');

        self::assertResponseRedirects('/');

        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Authentication could not be completed. Please try again.');
        self::assertSelectorExists('a[href="/auth/login?returnTo=/"]');
    }

    public function testLogoutClearsSessionAndRedirectsToAuth0Logout(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $client->request('GET', '/auth/logout');

        self::assertResponseRedirects();
        self::assertStringContainsString('https://test.auth0.com/v2/logout', (string) $client->getResponse()->headers->get('Location'));

        // The session cookie must be actively expired on the logout response so the
        // browser drops it rather than replaying the invalidated session.
        $sessionName = self::getContainer()->get('session.factory')->createSession()->getName();
        $cleared = null;
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $sessionName) {
                $cleared = $cookie;
            }
        }
        self::assertNotNull($cleared, 'Logout response must set a cookie for the session name.');
        self::assertTrue($cleared->isCleared(), 'Logout must expire the session cookie in the browser.');

        $client->request('GET', '/browse');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/auth/login?returnTo=/browse"]');
    }

    private function seedPendingLogin(KernelBrowser $client, array $pendingLogin): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        $session->set('auth0_login', $pendingLogin);
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    private function getSessionFromClient(KernelBrowser $client): SessionInterface
    {
        $sessionName = self::getContainer()->get('session.factory')->createSession()->getName();
        $sessionCookie = $client->getCookieJar()->get($sessionName);

        self::assertNotNull($sessionCookie);

        $session = self::getContainer()->get('session.factory')->createSession();
        $session->setId($sessionCookie->getValue());
        $session->start();

        return $session;
    }
}
