<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Auth0\Auth0User;
use App\Tests\Mock\PackageZipFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MauticUploadApiControllerTest extends WebTestCase
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
        $client->request('POST', '/api/package/mautic-upload');

        self::assertResponseStatusCodeSame(401);
    }

    public function testGetMethodNotAllowed(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/package/mautic-upload');

        self::assertResponseStatusCodeSame(405);
    }

    public function testUploadWithoutFileReturnsError(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('POST', '/api/package/mautic-upload');

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('No package file uploaded', $data['error']);
    }

    public function testSuccessfulUpload(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('POST', '/api/package/mautic-upload', files: [
            'package' => $this->validZipUpload(),
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('testuser/test-campaign', $data['package_name']);
        self::assertSame('1.0.0', $data['version']);
        self::assertTrue($data['created']);
    }

    public function testUploadWithNonZipBytesReturnsError(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $tmp = tempnam(sys_get_temp_dir(), 'upload_test_');
        file_put_contents($tmp, 'this is not a zip file at all');
        $this->zipPath = $tmp;

        $client->request('POST', '/api/package/mautic-upload', files: [
            'package' => new UploadedFile($tmp, 'campaign.zip', 'application/zip', test: true),
        ]);

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('not a valid ZIP archive', $data['error']);
    }

    public function testUploadWithUnknownVendorIsRejected(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $this->zipPath = PackageZipFactory::create([
            'name' => 'unknown-vendor/test-campaign',
            'description' => 'Placeholder vendor.',
            'type' => 'mautic-resource',
            'version' => '1.0.0',
        ]);

        $client->request('POST', '/api/package/mautic-upload', files: [
            'package' => new UploadedFile($this->zipPath, 'campaign.zip', 'application/zip', test: true),
        ]);

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('missing a vendor name', $data['error']);
    }

    public function testUploadWithOversizedMetadataIsRejected(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $this->zipPath = PackageZipFactory::create([
            'name' => 'testuser/test-campaign',
            'description' => 'A test campaign for the marketplace.',
            'type' => 'mautic-resource',
            'version' => '1.0.0',
            'extra' => ['mautic' => [
                'minimum-version' => '5.0',
                'headline' => str_repeat('x', 61),
            ]],
        ]);

        $client->request('POST', '/api/package/mautic-upload', files: [
            'package' => new UploadedFile($this->zipPath, 'campaign.zip', 'application/zip', test: true),
        ]);

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('Headline must be at most 60 characters', $data['error']);
    }

    public function testUploadWithoutComposerJsonIsRejected(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $this->zipPath = PackageZipFactory::create(omitComposer: true);

        $client->request('POST', '/api/package/mautic-upload', files: [
            'package' => new UploadedFile($this->zipPath, 'campaign.zip', 'application/zip', test: true),
        ]);

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('composer.json', $data['error']);
    }

    private function validZipUpload(): UploadedFile
    {
        $this->zipPath = PackageZipFactory::create();

        return new UploadedFile($this->zipPath, 'campaign.zip', 'application/zip', test: true);
    }
}
