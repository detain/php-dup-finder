<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

trait AuthenticationTestHelper
{
    protected function createAuthenticatedUser(int $id, string $email, bool $active = true): User
    {
        $user = new User();
        $user->id = $id;
        $user->email = $email;
        $user->is_active = $active;
        return $user;
    }

    protected function createValidTokenPair(TokenService $service, int $userId): array
    {
        return [
            'access_token' => $service->createAccessToken($userId),
            'refresh_token' => $service->createRefreshToken($userId),
            'expires_in' => 3600,
        ];
    }

    protected function assertValidLoginResponse(array $response): void
    {
        $this->assertIsArray($response);
        $this->assertArrayHasKey('access_token', $response);
        $this->assertArrayHasKey('refresh_token', $response);
        $this->assertArrayHasKey('expires_in', $response);
        $this->assertEquals('Bearer', $response['token_type'] ?? 'Bearer');
    }
}

trait ApiResponseAssertionHelper
{
    protected function assertSuccessfulJsonResponse(JsonResponse $response, int $expectedStatus = 200): void
    {
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals($expectedStatus, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
    }

    protected function assertValidationErrorResponse(JsonResponse $response, string $expectedField): void
    {
        $this->assertEquals(422, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey($expectedField, $data['errors']);
    }
}

trait ServiceLayerTestFixture
{
    protected function createMockOrder(int $id = 1, string $status = 'pending'): MockObject&Order
    {
        $order = $this->createMock(Order::class);
        $order->id = $id;
        $order->status = $status;
        $order->total_amount = 199.99;
        $order->currency = 'USD';
        return $order;
    }

    protected function createMockInventoryReservation(int $productId, int $quantity): array
    {
        return [
            'product_id' => $productId,
            'quantity_reserved' => $quantity,
            'reservation_id' => 'res_' . uniqid(),
            'reserved' => true,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+15 minutes')),
        ];
    }
}
