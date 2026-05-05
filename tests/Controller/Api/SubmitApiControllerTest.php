<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Auth0\Auth0User;
use App\Tests\Mock\PackageZipFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SubmitApiControllerTest extends WebTestCase
{
    private string $zipPath = '';

    protected function tearDown(): void
    {
        if ('' !== $this->zipPath && file_exists($this->zipPath)) {
            unlink($this->zipPath);
        }
        parent::tearDown();
    }

    public function testAnonymousSubmitRedirectsToLogin(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/submit');

        self::assertResponseRedirects();
    }

    public function testGetMethodNotAllowed(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/package/submit');

        self::assertResponseStatusCodeSame(405);
    }

    public function testSubmitWithoutZipReturns400(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $client->request('POST', '/api/package/submit', $this->validFormFields());

        self::assertResponseStatusCodeSame(400);
    }

    public function testSubmitWithMissingRequiredFieldsReturns422(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $client->request(
            'POST',
            '/api/package/submit',
            ['name' => '', 'version' => '', 'category' => '', 'headline' => '', 'description' => '', 'license_type' => '', 'works_with' => [], 'ip_ownership_accepted' => '0'],
            ['zip' => $this->validZipUpload()],
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testSubmitWithMismatchedComposerReturns400(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $fields = $this->validFormFields();
        $fields['name'] = 'someone-else/different-name';

        $client->request('POST', '/api/package/submit', $fields, ['zip' => $this->validZipUpload()]);

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('does not match', $payload['error']);
    }

    public function testSuccessfulSubmitReturns201AndStatusPending(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $client->request(
            'POST',
            '/api/package/submit',
            $this->validFormFields(),
            ['zip' => $this->validZipUpload()],
        );

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('testuser/test-campaign', $data['package_name']);
        self::assertSame('1.0.0', $data['version']);
        self::assertSame('pending', $data['status']);
        self::assertTrue($data['created']);
    }

    public function testSubmitWithInvalidZipReturns400(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $this->zipPath = tempnam(sys_get_temp_dir(), 'bad_');
        file_put_contents($this->zipPath, 'not-a-zip');

        $client->request(
            'POST',
            '/api/package/submit',
            $this->validFormFields(),
            ['zip' => new UploadedFile($this->zipPath, 'package.zip', 'application/zip', null, true)],
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testSubmitRequiresIpOwnershipAccepted(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $fields = $this->validFormFields();
        $fields['ip_ownership_accepted'] = '0';

        $client->request('POST', '/api/package/submit', $fields, ['zip' => $this->validZipUpload()]);

        self::assertResponseStatusCodeSame(422);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $fields = array_column($payload['violations'], 'field');
        self::assertContains('ip_ownership_accepted', $fields);
    }

    public function testSubmitRejectsHeadlineLongerThan60Chars(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $fields = $this->validFormFields();
        $fields['headline'] = str_repeat('x', 61);

        $client->request('POST', '/api/package/submit', $fields, ['zip' => $this->validZipUpload()]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return array<string, mixed>
     */
    private function validFormFields(): array
    {
        return [
            'name' => 'testuser/test-campaign',
            'version' => '1.0.0',
            'category' => 'mautic-resource',
            'headline' => 'A short headline',
            'keywords' => ['test', 'campaign'],
            'description' => str_repeat('Detailed description for the package. ', 3),
            'languages' => ['en'],
            'works_with' => ['5.2', '5.1'],
            'license_type' => 'MIT',
            'price' => '0',
            'ip_ownership_accepted' => '1',
        ];
    }

    private function validZipUpload(): UploadedFile
    {
        $this->zipPath = PackageZipFactory::create();

        return new UploadedFile($this->zipPath, 'package.zip', 'application/zip', null, true);
    }
}
