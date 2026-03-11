<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class Auth0MockHttpClient extends MockHttpClient
{
    public function __construct()
    {
        parent::__construct(static function (): MockResponse {
            return new MockResponse(
                json_encode([
                    'sub' => 'auth0|test123',
                    'name' => 'Test User',
                    'email' => 'test@example.com',
                    'picture' => null,
                ], \JSON_THROW_ON_ERROR),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
            );
        });
    }
}
