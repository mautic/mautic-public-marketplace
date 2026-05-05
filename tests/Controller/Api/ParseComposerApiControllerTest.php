<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Auth0\Auth0User;
use App\Tests\Mock\PackageZipFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ParseComposerApiControllerTest extends WebTestCase
{
    private string $zipPath = '';

    protected function tearDown(): void
    {
        if ('' !== $this->zipPath && file_exists($this->zipPath)) {
            unlink($this->zipPath);
        }
        parent::tearDown();
    }

    public function testAnonymousIsRedirected(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/parse-composer');

        self::assertResponseRedirects();
    }

    public function testParseReturnsComposerFields(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $this->zipPath = PackageZipFactory::create();
        $client->request(
            'POST',
            '/api/package/parse-composer',
            [],
            ['zip' => new UploadedFile($this->zipPath, 'package.zip', 'application/zip', null, true)],
        );

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('testuser/test-campaign', $data['name']);
        self::assertSame('1.0.0', $data['version']);
        self::assertSame('mautic-resource', $data['type']);
        self::assertSame(['test', 'campaign'], $data['keywords']);
        self::assertSame('^5.0', $data['mautic_version_constraint']);
    }

    public function testParseRejectsZipWithoutComposer(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $this->zipPath = PackageZipFactory::create(omitComposer: true);
        $client->request(
            'POST',
            '/api/package/parse-composer',
            [],
            ['zip' => new UploadedFile($this->zipPath, 'package.zip', 'application/zip', null, true)],
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testParseRejectsInvalidZip(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $this->zipPath = tempnam(sys_get_temp_dir(), 'bad_');
        file_put_contents($this->zipPath, 'not-a-zip');

        $client->request(
            'POST',
            '/api/package/parse-composer',
            [],
            ['zip' => new UploadedFile($this->zipPath, 'package.zip', 'application/zip', null, true)],
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testParseWithoutZipReturns400(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $client->request('POST', '/api/package/parse-composer');

        self::assertResponseStatusCodeSame(400);
    }
}
