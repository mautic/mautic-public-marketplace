<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Auth0\Auth0User;
use App\Tests\Fixtures\ValidZipFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UploadApiControllerTest extends WebTestCase
{
    public function testAnonymousUploadRedirectsToLogin(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/upload', files: [
            'package' => $this->makeUploadedFile(ValidZipFixture::build()),
        ]);

        self::assertResponseRedirects();
    }

    public function testGetMethodNotAllowed(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/package/upload');

        self::assertResponseStatusCodeSame(405);
    }

    public function testUploadWithoutFileReturnsError(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('POST', '/api/package/upload');

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('No package file uploaded', $data['error']);
    }

    public function testSuccessfulUpload(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $client->request('POST', '/api/package/upload', files: [
            'package' => $this->makeUploadedFile(ValidZipFixture::build()),
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
        $client->request('POST', '/api/package/upload', files: [
            'package' => $this->makeUploadedFile('this is not a zip file at all'),
        ]);

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('not a valid ZIP archive', $data['error']);
    }

    public function testUploadWithUnknownVendorIsRejected(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');
        $zipBytes = ValidZipFixture::build(json_encode([
            'name' => 'unknown-vendor/test-campaign',
            'description' => 'Placeholder vendor.',
            'type' => 'mautic-resource',
            'version' => '1.0.0',
        ]));
        $client->request('POST', '/api/package/upload', files: [
            'package' => $this->makeUploadedFile($zipBytes),
        ]);

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('missing a vendor name', $data['error']);
    }

    private function makeUploadedFile(string $contents, string $originalName = 'campaign.zip'): UploadedFile
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'upload_test_');
        if (false === $tmpFile) {
            throw new \RuntimeException('Failed to create temp file for test upload.');
        }
        file_put_contents($tmpFile, $contents);

        return new UploadedFile($tmpFile, $originalName, 'application/zip', test: true);
    }
}
