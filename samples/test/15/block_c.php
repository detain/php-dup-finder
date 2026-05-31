<?php

declare(strict_types=1);

namespace Tests\Shared\Assertions;

use Illuminate\Http\JsonResponse;

trait ApiResponseAssertionsTrait
{
    private function assertJsonResponse(JsonResponse $response, int $expectedStatus, array $expectedData = []): void
    {
        $this->assertEquals($expectedStatus, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));

        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);

        if (!empty($expectedData)) {
            foreach ($expectedData as $key => $value) {
                $this->assertArrayHasKey($key, $content);
                $this->assertEquals($value, $content[$key]);
            }
        }
    }

    private function assertPaginatedJsonResponse(JsonResponse $response, int $expectedStatus, array $expectedPagination = []): void
    {
        $this->assertEquals($expectedStatus, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));

        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);
        $this->assertArrayHasKey('data', $content);
        $this->assertArrayHasKey('meta', $content);
        $this->assertArrayHasKey('links', $content);

        if (!empty($expectedPagination)) {
            $this->assertEquals($expectedPagination['total'] ?? null, $content['meta']['total'] ?? null);
            $this->assertEquals($expectedPagination['per_page'] ?? null, $content['meta']['per_page'] ?? null);
            $this->assertEquals($expectedPagination['current_page'] ?? null, $content['meta']['current_page'] ?? null);
        }
    }

    private function assertErrorJsonResponse(JsonResponse $response, int $expectedStatus, string $expectedError): void
    {
        $this->assertEquals($expectedStatus, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));

        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);
        $this->assertArrayHasKey('error', $content);
        $this->assertEquals($expectedError, $content['error']);
    }
}
