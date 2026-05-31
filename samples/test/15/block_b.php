<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use App\Http\Controllers\Api\OrderController;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;

class OrderControllerTest extends TestCase
{
    private OrderController $controller;
    private $mockOrderService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockOrderService = Mockery::mock(OrderService::class);
        $this->controller = new OrderController($this->mockOrderService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

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

    public function testIndexReturnsPaginatedOrders(): void
    {
        $paginatedOrders = new LengthAwarePaginator(
            items: [
                ['id' => 1, 'order_number' => 'ORD-001', 'total' => 9999],
                ['id' => 2, 'order_number' => 'ORD-002', 'total' => 14999],
            ],
            total: 100,
            perPage: 20,
            currentPage: 1
        );

        $this->mockOrderService
            ->shouldReceive('getPaginatedOrders')
            ->with(20, 1, ['status' => 'pending'])
            ->andReturn($paginatedOrders);

        $request = new \Illuminate\Http\Request(['status' => 'pending']);
        $response = $this->controller->index($request);

        $this->assertPaginatedJsonResponse($response, 200, [
            'total' => 100,
            'per_page' => 20,
            'current_page' => 1,
        ]);
    }

    public function testShowReturnsOrderById(): void
    {
        $order = [
            'id' => 1,
            'order_number' => 'ORD-001',
            'customer_email' => 'customer@example.com',
            'total' => 9999,
            'status' => 'processing',
        ];

        $this->mockOrderService
            ->shouldReceive('findById')
            ->with(1)
            ->andReturn($order);

        $response = $this->controller->show(1);

        $this->assertJsonResponse($response, 200, ['order_number' => 'ORD-001']);
    }

    public function testStoreCreatesNewOrder(): void
    {
        $newOrder = ['id' => 50, 'order_number' => 'ORD-050', 'total' => 5999];
        $requestData = [
            'customer_email' => 'new@example.com',
            'items' => [['product_id' => 1, 'quantity' => 2]],
            'shipping_address' => ['street' => '123 Main St'],
        ];

        $this->mockOrderService
            ->shouldReceive('createOrder')
            ->with(Mockery::type(CreateOrderRequest::class))
            ->andReturn($newOrder);

        $request = new CreateOrderRequest();
        $request->replace($requestData);

        $response = $this->controller->store($request);

        $this->assertJsonResponse($response, 201, ['id' => 50]);
    }

    public function testUpdateModifiesExistingOrder(): void
    {
        $updatedOrder = ['id' => 1, 'status' => 'shipped'];

        $this->mockOrderService
            ->shouldReceive('updateOrder')
            ->with(1, Mockery::type(\Illuminate\Http\Request::class))
            ->andReturn($updatedOrder);

        $request = new \Illuminate\Http\Request(['status' => 'shipped']);
        $response = $this->controller->update($request, 1);

        $this->assertJsonResponse($response, 200, ['status' => 'shipped']);
    }

    public function testDestroyRemovesOrder(): void
    {
        $this->mockOrderService
            ->shouldReceive('deleteOrder')
            ->with(1)
            ->andReturn(true);

        $response = $this->controller->destroy(1);

        $this->assertEquals(204, $response->getStatusCode());
    }
}
