<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use Stripe\HttpClient\ClientInterface;

/**
 * Canned Stripe HTTP client for unit tests: returns fixed JSON per endpoint and records
 * the requests so tests can assert what the StripeConnectClient sent, without any network.
 */
final class StripeMockHttpClient implements ClientInterface
{
    /**
     * @var list<array{method: string, url: string, params: array<mixed>}>
     */
    public array $requests = [];

    /**
     * @param array<mixed> $headers
     * @param array<mixed> $params
     *
     * @return array{0: string, 1: int, 2: array<mixed>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => (string) $method, 'url' => (string) $absUrl, 'params' => (array) $params];

        return [$this->bodyFor((string) $absUrl), 200, []];
    }

    private function bodyFor(string $url): string
    {
        $body = match (true) {
            str_contains($url, '/v1/account_links') => [
                'object' => 'account_link',
                'url' => 'https://connect.stripe.com/setup/s/test',
            ],
            str_contains($url, '/v1/accounts') => [
                'id' => 'acct_test_123',
                'object' => 'account',
                'charges_enabled' => true,
                'payouts_enabled' => true,
                'details_submitted' => true,
            ],
            str_contains($url, '/v1/products') => [
                'id' => 'prod_test_123',
                'object' => 'product',
            ],
            str_contains($url, '/v1/prices') => [
                'id' => 'price_test_123',
                'object' => 'price',
            ],
            str_contains($url, '/v1/checkout/sessions') => [
                'id' => 'cs_test_123',
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
            ],
            default => ['object' => 'unknown'],
        };

        return (string) json_encode($body);
    }
}
