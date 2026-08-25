<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tests\Mock\StripeMockHttpClient;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;

/**
 * The test environment deliberately leaves STRIPE_SECRET_KEY empty, so every Stripe
 * path takes its "not configured" branch and nothing reaches the network. Tests that
 * need the configured behaviour turn it on for their own duration with this trait,
 * pointing the SDK's global HTTP client at a recording mock.
 */
trait ConfiguresStripe
{
    private ?StripeMockHttpClient $stripeHttp = null;

    /**
     * Value STRIPE_SECRET_KEY held before the override. Restoring it matters: unsetting
     * the variable outright would leave the container unable to resolve the env var at
     * all, breaking every later test in the process.
     *
     * @var array{server: mixed, env: mixed}|null
     */
    private ?array $stripeKeyBackup = null;

    private function enableStripe(): StripeMockHttpClient
    {
        $this->stripeKeyBackup = [
            'server' => $_SERVER['STRIPE_SECRET_KEY'] ?? null,
            'env' => $_ENV['STRIPE_SECRET_KEY'] ?? null,
        ];

        $_SERVER['STRIPE_SECRET_KEY'] = 'sk_test_x';
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_x';

        $this->stripeHttp = new StripeMockHttpClient();
        ApiRequestor::setHttpClient($this->stripeHttp);

        return $this->stripeHttp;
    }

    private function restoreStripe(): void
    {
        if (null === $this->stripeKeyBackup) {
            return;
        }

        $_SERVER['STRIPE_SECRET_KEY'] = $this->stripeKeyBackup['server'];
        $_ENV['STRIPE_SECRET_KEY'] = $this->stripeKeyBackup['env'];
        $this->stripeKeyBackup = null;
        $this->stripeHttp = null;

        ApiRequestor::setHttpClient(new CurlClient());
    }

    /**
     * The parameters sent to the first Stripe endpoint whose URL contains $urlFragment.
     *
     * @return array<mixed>
     */
    private function stripeRequestParams(string $urlFragment): array
    {
        foreach ($this->recordedStripeRequests() as $request) {
            if (str_contains($request['url'], $urlFragment)) {
                return $request['params'];
            }
        }

        self::fail(\sprintf('No Stripe request was sent to "%s".', $urlFragment));
    }

    private function stripeRequestCount(string $urlFragment): int
    {
        $count = 0;
        foreach ($this->recordedStripeRequests() as $request) {
            if (str_contains($request['url'], $urlFragment)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return list<array{method: string, url: string, params: array<mixed>}>
     */
    private function recordedStripeRequests(): array
    {
        if (null === $this->stripeHttp) {
            self::fail('Stripe was not enabled for this test — call enableStripe() first.');
        }

        return $this->stripeHttp->requests;
    }
}
