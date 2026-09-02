<?php

declare(strict_types=1);

namespace App\Tests\Supabase;

use App\Supabase\Exception\SupabaseApiException;
use App\Supabase\SupabaseClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SupabaseClientTest extends TestCase
{
    private function client(MockResponse|callable ...$responses): SupabaseClient
    {
        $factory = 1 === \count($responses) && \is_callable($responses[0]) ? $responses[0] : $responses;

        return new SupabaseClient(
            new MockHttpClient($factory, 'https://supabase.test'),
            'https://supabase.test',
            'anon-key',
            'service-role-key',
        );
    }

    public function testQueryReturnsDecodedJson(): void
    {
        $client = $this->client(new MockResponse('[{"name":"acme/widget"}]', ['http_code' => 200]));

        self::assertSame([['name' => 'acme/widget']], $client->query('GET', '/rest/v1/packages', []));
    }

    public function testNonJsonSuccessBodyThrowsSupabaseException(): void
    {
        // A proxy/storage HTML page returned with a 200 used to bubble up as an uncaught
        // JsonException from toArray(); it must now surface as a handled SupabaseApiException.
        $client = $this->client(new MockResponse('<html>Bad Gateway</html>', ['http_code' => 200]));

        $this->expectException(SupabaseApiException::class);
        $client->query('GET', '/rest/v1/packages', []);
    }

    public function testBareJsonNullBodyDecodesToNull(): void
    {
        // PostgREST answers an RPC that returned SQL NULL with a literal `null` body — what
        // get_pack sends for an unknown package name. That is a "no row" answer, not the
        // non-JSON body that used to surface as an error on the package detail page.
        $client = $this->client(new MockResponse('null', ['http_code' => 200]));

        self::assertNull($client->query('GET', '/rest/v1/rpc/get_pack', ['packag_name' => 'unicorn']));
    }

    public function testEmptySuccessBodyDecodesToEmptyArray(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 204]));

        self::assertSame([], $client->mutate('PATCH', '/rest/v1/packages', ['x' => 1]));
    }

    public function testErrorStatusWithJsonMessageThrowsWithThatMessage(): void
    {
        $client = $this->client(new MockResponse('{"message":"column missing"}', ['http_code' => 400]));

        $this->expectException(SupabaseApiException::class);
        $this->expectExceptionMessage('column missing');
        $client->mutate('POST', '/rest/v1/packages', ['x' => 1]);
    }

    public function testErrorStatusWithNonJsonBodyThrowsSupabaseException(): void
    {
        $client = $this->client(new MockResponse('<html>500</html>', ['http_code' => 500]));

        $this->expectException(SupabaseApiException::class);
        $client->query('GET', '/rest/v1/packages', []);
    }

    public function testTransportFailureThrowsSupabaseException(): void
    {
        $client = $this->client(new MockResponse('', ['error' => 'Connection refused']));

        $this->expectException(SupabaseApiException::class);
        $client->query('GET', '/rest/v1/packages', []);
    }

    public function testStorageUploadReturnsPublicUrlOnSuccess(): void
    {
        // First request is the pre-delete, second is the upload POST.
        $client = $this->client(
            new MockResponse('', ['http_code' => 404]),
            new MockResponse('{"Key":"package-media/foo"}', ['http_code' => 200]),
        );

        $url = $client->uploadStorageObject('package-media', 'foo/banner.png', 'bytes', 'image/png');

        self::assertSame('https://supabase.test/storage/v1/object/public/package-media/foo/banner.png', $url);
    }

    public function testStorageUploadNonJsonErrorThrowsSupabaseException(): void
    {
        // A non-JSON storage error body (e.g. an nginx HTML 413 page) used to crash in
        // toArray(); it must now be a handled SupabaseApiException.
        $client = $this->client(
            new MockResponse('', ['http_code' => 404]),
            new MockResponse('<html>413 Request Entity Too Large</html>', ['http_code' => 413]),
        );

        $this->expectException(SupabaseApiException::class);
        $client->uploadStorageObject('package-media', 'foo/banner.png', 'bytes', 'image/png');
    }
}
