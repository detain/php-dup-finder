<?php

declare(strict_types=1);

namespace Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use App\Repositories\OrderRepository;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderService;
use Mockery;
use Mockery\MockInterface;

class OrderServiceTest extends TestCase
{
    private OrderService $orderService;
    private MockInterface $orderRepository;
    private MockInterface $eventDispatcher;
    private MockInterface $inventoryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = Mockery::mock(OrderRepository::class);
        $this->eventDispatcher = Mockery::mock(\App\Services\EventDispatcher::class);
        $this->inventoryService = Mockery::mock(\App\Services\InventoryService::class);

        $this->orderService = new OrderService(
            $this->orderRepository,
            $this->eventDispatcher,
            $this->inventoryService
        );

        // Configure default repository behavior
        $this->orderRepository->shouldReceive('getConnection')
            ->andReturn(Mockery::mock(\Doctrine\DBAL\Connection::class));
        $this->orderRepository->shouldReceive('getTableName')
            ->andReturn('orders');
        $this->orderRepository->shouldReceive('getModelClass')
            ->andReturn(Order::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createMockOrder(array $attributes = []): Order
    {
        $defaults = [
            'id' => 1,
            'order_number' => 'ORD-001',
            'customer_email' => 'customer@example.com',
            'status' => 'pending',
            'total' => 9999,
            'created_at' => new \DateTimeImmutable(),
            'updated_at' => new \DateTimeImmutable(),
        ];

        return new Order(array_merge($defaults, $attributes));
    }

    private function createMockOrderItem(array $attributes = []): OrderItem
    {
        $defaults = [
            'id' => 1,
            'order_id' => 1,
            'product_id' => 101,
            'quantity' => 2,
            'unit_price' => 2999,
            'total' => 5998,
        ];

        return new OrderItem(array_merge($defaults, $attributes));
    }

    public function testFindsOrderByOrderNumber(): void
    {
        $order = $this->createMockOrder(['order_number' => 'ORD-12345']);

        $this->orderRepository
            ->shouldReceive('findByOrderNumber')
            ->with('ORD-12345')
            ->once()
            ->andReturn($order);

        $result = $this->orderService->findByOrderNumber('ORD-12345');

        $this->assertSame($order, $result);
    }

    public function testFindsOrderById(): void
    {
        $order = $this->createMockOrder(['id' => 42]);

        $this->orderRepository
            ->shouldReceive('findById')
            ->with(42)
            ->once()
            ->andReturn($order);

        $result = $this->orderService->findById(42);

        $this->assertSame($order, $result);
    }

    public function testSavesOrderWithUpdatedTimestamp(): void
    {
        $order = $this->createMockOrder(['id' => 1]);

        $this->orderRepository
            ->shouldReceive('save')
            ->with(Mockery::on(function ($arg) use ($order) {
                return $arg instanceof Order && $arg->id === $order->id;
            }))
            ->once()
            ->andReturn($order);

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->with(Mockery::type(\App\Events\OrderUpdated::class))
            ->once();

        $result = $this->orderService->save($order);

        $this->assertSame($order, $result);
    }

    public function testUpdatesOrderStatusAndDispatchesEvent(): void
    {
        $order = $this->createMockOrder(['id' => 99, 'status' => 'processing']);

        $this->orderRepository
            ->shouldReceive('updateStatus')
            ->with(99, 'shipped')
            ->once()
            ->andReturn(true);

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->with(Mockery::on(function ($event) {
                return $event instanceof \App\Events\OrderShipped
                    && $event->getOrderId() === 99;
            }))
            ->once();

        $result = $this->orderService->updateStatus(99, 'shipped');

        $this->assertTrue($result);
    }

    public function testCancelsOrderAndRestoresInventory(): void
    {
        $order = $this->createMockOrder(['id' => 50, 'status' => 'pending']);
        $items = [
            $this->createMockOrderItem(['product_id' => 101, 'quantity' => 3]),
            $this->createMockOrderItem(['product_id' => 202, 'quantity' => 1]),
        ];

        $this->orderRepository
            ->shouldReceive('findById')
            ->with(50)
            ->andReturn($order);

        $this->orderRepository
            ->shouldReceive('getOrderItems')
            ->with(50)
            ->andReturn($items);

        $this->inventoryService
            ->shouldReceive('restoreInventory')
            ->times(2)
            ->andReturn(true);

        $this->orderRepository
            ->shouldReceive('updateStatus')
            ->with(50, 'cancelled')
            ->once()
            ->andReturn(true);

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->with(Mockery::type(\App\Events\OrderCancelled::class))
            ->once();

        $result = $this->orderService->cancel(50);

        $this->assertTrue($result);
    }
}
