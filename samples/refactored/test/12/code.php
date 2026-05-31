<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;
use Illuminate\Http\JsonResponse;

trait ApiResponseAssertions
{
    protected function assertJsonResponse(JsonResponse $response, int $expectedStatus, ?string $key = null): void
    {
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals($expectedStatus, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);

        if ($key !== null) {
            $this->assertArrayHasKey($key, $data);
        }
    }

    protected function assertSuccessfulJsonResponse(JsonResponse $response, string $dataKey = 'data'): void
    {
        $this->assertJsonResponse($response, 200, $dataKey);
    }

    protected function assertCreatedJsonResponse(JsonResponse $response, string $expectedKey = null): void
    {
        $this->assertJsonResponse($response, 201, 'data');

        if ($expectedKey !== null) {
            $data = json_decode($response->getContent(), true);
            $this->assertArrayHasKey($expectedKey, $data['data']);
        }
    }

    protected function assertNotFoundJsonResponse(JsonResponse $response): void
    {
        $this->assertJsonResponse($response, 404, 'error');
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('not found', strtolower($data['message']));
    }

    protected function assertValidationErrorJsonResponse(JsonResponse $response, string $expectedField): void
    {
        $this->assertJsonResponse($response, 422, 'errors');
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey($expectedField, $data['errors']);
    }

    protected function assertBadRequestJsonResponse(JsonResponse $response, ?string $fieldName = null): void
    {
        $this->assertJsonResponse($response, 400, 'error');

        if ($fieldName !== null) {
            $data = json_decode($response->getContent(), true);
            $this->assertStringContainsString($fieldName, strtolower($data['message']));
        }
    }

    protected function assertNoContentResponse(JsonResponse $response): void
    {
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(204, $response->getStatusCode());
    }

    protected function assertServerErrorJsonResponse(JsonResponse $response): void
    {
        $this->assertJsonResponse($response, 500, 'error');
    }

    protected function assertRateLimitedJsonResponse(JsonResponse $response): void
    {
        $this->assertJsonResponse($response, 429, 'error');
    }
}
