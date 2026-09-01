<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ErrorPageTest extends WebTestCase
{
    /**
     * The template is rendered directly: the test kernel runs in debug mode, where Symfony
     * serves the exception trace instead of the production error page.
     */
    public function testNotFoundPageIsBranded(): void
    {
        self::createClient();

        $request = Request::create('/package/mautic/unicorn');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $container = self::getContainer();
        $container->get(RequestStack::class)->push($request);

        $html = $container->get('twig')->render('@Twig/Exception/error404.html.twig', [
            'status_code' => 404,
            'status_text' => 'Not Found',
        ]);

        self::assertStringContainsString('find that page', $html);
        self::assertStringContainsString('href="/browse"', $html);
        self::assertStringContainsString('Browse the marketplace', $html);
    }
}
