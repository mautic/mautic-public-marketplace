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

    public function testAnonymousReturns401(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/parse-composer');

        self::assertResponseStatusCodeSame(401);
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
        // Defaults when the archive carries no extra.mautic share metadata.
        self::assertNull($data['headline']);
        self::assertSame([], $data['languages']);
        // works_with is derived from the default composer's minimum-version (5.0).
        self::assertSame(['5.x'], $data['works_with']);
        self::assertNull($data['price']);
    }

    public function testParseReturnsMauticShareMetadata(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $this->zipPath = PackageZipFactory::create([
            'name' => 'testuser/test-campaign',
            'description' => 'A test campaign for the marketplace.',
            'type' => 'mautic-resource',
            'version' => '1.0.0',
            'keywords' => ['test', 'campaign'],
            'license' => 'MIT',
            'require' => ['mautic/core-lib' => '^5.0'],
            'extra' => ['mautic' => [
                'minimum-version' => '5.0',
                'headline' => 'Stuck campaigns A, B, C, D',
                'languages' => ['en', 'de'],
                'works-with' => ['7.x', '6.x'],
                'price' => ['amount' => 12.5],
            ]],
        ]);
        $client->request(
            'POST',
            '/api/package/parse-composer',
            [],
            ['zip' => new UploadedFile($this->zipPath, 'package.zip', 'application/zip', null, true)],
        );

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Stuck campaigns A, B, C, D', $data['headline']);
        self::assertSame(['en', 'de'], $data['languages']);
        self::assertSame(['7.x', '6.x'], $data['works_with']);
        self::assertSame(12.5, $data['price']);
    }

    public function testParseDerivesWorksWithFromMinimumVersion(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $this->zipPath = PackageZipFactory::create([
            'name' => 'testuser/test-campaign',
            'description' => 'A test campaign for the marketplace.',
            'type' => 'mautic-resource',
            'version' => '1.0.0',
            // No works-with: the minimum-version should still prefill the checkboxes.
            'extra' => ['mautic' => ['minimum-version' => '7.0']],
        ]);
        $client->request(
            'POST',
            '/api/package/parse-composer',
            [],
            ['zip' => new UploadedFile($this->zipPath, 'package.zip', 'application/zip', null, true)],
        );

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['7.x'], $data['works_with']);
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
