<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Auth0\Auth0User;
use App\Tests\Mock\PackageZipFactory;
use App\Tests\Mock\SupabaseMockHttpClient;
use App\Tests\Support\ConfiguresStripe;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SubmitApiControllerTest extends WebTestCase
{
    use ConfiguresStripe;

    private string $zipPath = '';

    protected function tearDown(): void
    {
        if ('' !== $this->zipPath && file_exists($this->zipPath)) {
            unlink($this->zipPath);
        }

        $this->restoreStripe();

        parent::tearDown();
    }

    public function testAnonymousSubmitReturns401(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/package/submit');

        // API routes return a JSON 401 (not a redirect) so the wizard's fetch() can detect
        // an expired session instead of silently following a redirect to the login page.
        self::assertResponseStatusCodeSame(401);
        self::assertJson((string) $client->getResponse()->getContent());
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

    public function testSubmitWithMismatchedComposerVersionReturns400(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $fields = $this->validFormFields();
        $fields['version'] = '9.9.9';

        $client->request('POST', '/api/package/submit', $fields, ['zip' => $this->validZipUpload()]);

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('does not match', $payload['error']);
    }

    public function testSubmitAllowsPackageNameOverride(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        // The upload form lets publishers set the marketplace name, so a name
        // that differs from composer.json is accepted (the form value wins).
        $fields = $this->validFormFields();
        $fields['name'] = 'someone-else/renamed-package';

        $client->request('POST', '/api/package/submit', $fields, ['zip' => $this->validZipUpload()]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('someone-else/renamed-package', $data['package_name']);
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

    public function testSubmitRejectsPublishingOverAnotherUsersPackage(): void
    {
        $client = self::createClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        // mautic/zebra-theme is owned by auth0|other; test123 must not be able to
        // publish over it (supply-chain takeover guard).
        $fields = $this->validFormFields();
        $fields['name'] = 'mautic/zebra-theme';
        $client->request('POST', '/api/package/submit', $fields, ['zip' => $this->validZipUpload()]);

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('another user', $payload['error']);
    }

    public function testPaidPublishRequiresAConnectedStripeAccount(): void
    {
        $client = $this->stripeEnabledClient();
        $client->loginUser(new Auth0User('auth0|test123', 'Test User', 'test@example.com', null), 'main');

        $client->request('POST', '/api/package/submit', $this->paidFormFields('testuser/test-campaign', '19.99'), ['zip' => $this->validZipUpload()]);

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('Connect your Stripe account', $payload['error']);
    }

    public function testPaidPublishIsRefusedWhileStripeStillReviewsTheVendor(): void
    {
        // The vendor submitted their details but Stripe has not activated transfers yet.
        // Publishing now would list a package whose checkout is guaranteed to fail.
        $client = $this->stripeEnabledClient();
        $client->loginUser(new Auth0User('auth0|pending', 'Pending Vendor', 'pending@example.com', null), 'main');

        $client->request('POST', '/api/package/submit', $this->paidFormFields('pending/new-package', '19.99'), ['zip' => $this->validZipUpload()]);

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('cannot receive payouts yet', $payload['error']);
        self::assertSame(0, $this->stripeRequestCount('/v1/products'));
    }

    public function testTurningAFreePackagePaidCreatesTheStripeProductAndPrice(): void
    {
        // The package already exists, so this is not a first publish — the product and
        // price still have to be created, or the package could never be bought.
        $client = $this->stripeEnabledClient();
        $client->loginUser(new Auth0User('auth0|vendor', 'Vendor', 'vendor@example.com', null), 'main');

        $client->request('POST', '/api/package/submit', $this->paidFormFields('vendor/free-plugin', '19.99'), ['zip' => $this->validZipUpload()]);

        self::assertResponseStatusCodeSame(201);

        $written = $this->lastPackageWrite();
        self::assertSame('prod_test_123', $written['stripe_product_id'] ?? null);
        self::assertSame('price_test_123', $written['stripe_price_id'] ?? null);
        self::assertSame('acct_vendor', $written['vendor_stripe_account_id'] ?? null);
    }

    public function testChangingThePriceCreatesANewStripePriceOnTheSameProduct(): void
    {
        // Stripe prices are immutable, so the stored price id has to move to a new one;
        // otherwise the detail page would show 19.99 while checkout charged 9.99.
        $client = $this->stripeEnabledClient();
        $client->loginUser(new Auth0User('auth0|vendor', 'Vendor', 'vendor@example.com', null), 'main');

        $client->request('POST', '/api/package/submit', $this->paidFormFields('vendor/paid-plugin', '19.99'), ['zip' => $this->validZipUpload()]);

        self::assertResponseStatusCodeSame(201);

        // A new price id, not the stored price_existing one.
        $written = $this->lastPackageWrite();
        self::assertSame('price_test_123', $written['stripe_price_id'] ?? null);
        // The product is reused, so it is not re-sent.
        self::assertArrayNotHasKey('stripe_product_id', $written);
    }

    public function testRepublishingAtTheSamePriceKeepsTheExistingStripePrice(): void
    {
        $client = $this->stripeEnabledClient();
        $client->loginUser(new Auth0User('auth0|vendor', 'Vendor', 'vendor@example.com', null), 'main');

        $client->request('POST', '/api/package/submit', $this->paidFormFields('vendor/paid-plugin', '9.99'), ['zip' => $this->validZipUpload()]);

        self::assertResponseStatusCodeSame(201);

        // Neither reference is re-sent, so the stored ones survive the update untouched.
        $written = $this->lastPackageWrite();
        self::assertArrayNotHasKey('stripe_price_id', $written);
        self::assertArrayNotHasKey('stripe_product_id', $written);
    }

    /**
     * @return array<string, mixed>
     */
    private function paidFormFields(string $name, string $price): array
    {
        return array_merge($this->validFormFields(), [
            'name' => $name,
            'pricing_model' => 'paid',
            'price' => $price,
            'currency' => 'EUR',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastPackageWrite(): array
    {
        $writes = self::getContainer()->get(SupabaseMockHttpClient::class)->recordedPackageWrites;
        self::assertNotSame([], $writes, 'The publish flow wrote nothing to the packages table.');

        return $writes[array_key_last($writes)];
    }

    private function stripeEnabledClient(): KernelBrowser
    {
        $this->enableStripe();

        return self::createClient();
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
