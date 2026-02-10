<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReviewApiControllerTest extends WebTestCase
{
    public function testSubmitWithoutAuthorizationHeader(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/review/vendor/package', content: json_encode([
            'rating' => 5,
            'review' => 'Great plugin!',
        ]));

        self::assertResponseStatusCodeSame(401);
    }

    public function testSubmitWithNonBearerAuthorization(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/review/vendor/package', server: [
            'HTTP_AUTHORIZATION' => 'Basic abc123',
        ], content: json_encode([
            'rating' => 5,
            'review' => 'Great plugin!',
        ]));

        self::assertResponseStatusCodeSame(401);
    }

    public function testGetMethodNotAllowed(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/review/vendor/package');

        self::assertResponseStatusCodeSame(405);
    }

    public function testSubmitWithInvalidRatingTooLow(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/review/vendor/package', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], content: json_encode([
            'rating' => 0,
            'review' => 'Great plugin!',
        ]));

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Rating must be between 1 and 5', (string) $client->getResponse()->getContent());
    }

    public function testSubmitWithInvalidRatingTooHigh(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/review/vendor/package', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], content: json_encode([
            'rating' => 6,
            'review' => 'Great plugin!',
        ]));

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Rating must be between 1 and 5', (string) $client->getResponse()->getContent());
    }

    public function testSubmitWithEmptyReview(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/review/vendor/package', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], content: json_encode([
            'rating' => 4,
            'review' => '',
        ]));

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Review text is required', (string) $client->getResponse()->getContent());
    }

    public function testSubmitWithWhitespaceOnlyReview(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/review/vendor/package', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], content: json_encode([
            'rating' => 4,
            'review' => '   ',
        ]));

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Review text is required', (string) $client->getResponse()->getContent());
    }

    public function testSuccessfulSubmit(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/review/vendor/package', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], content: json_encode([
            'rating' => 5,
            'review' => 'Great plugin!',
        ]));

        self::assertResponseStatusCodeSame(201);
    }

    public function testSubmitWithInvalidJson(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/review/vendor/package', server: [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], content: 'not-json');

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Could not decode request body', (string) $client->getResponse()->getContent());
    }
}
