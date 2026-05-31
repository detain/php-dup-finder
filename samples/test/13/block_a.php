<?php

declare(strict_types=1);

namespace Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Customer;
use App\Services\OrderService;
use App\Exceptions\InsufficientInventoryException;
use App\Exceptions\InvalidCouponException;
use Mockery;

class OrderCreationTransactionTest extends TestCase
{
    private OrderService $orderService;
    private $mockProductRepository;
    private $mockOrderRepository;
    private $mockCustomerRepository;
    private $mockEventDispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockProductRepository = Mockery::mock(\App\Repositories\ProductRepository::class);
        $this->mockOrderRepository = Mockery::mock(\App\Repositories\OrderRepository::class);
        $this->mockCustomerRepository = Mockery::mock(\App\Repositories\CustomerRepository::class);
        $this->mockEventDispatcher = Mockery::mock(\App\Services\EventDispatcher::class);

        $this->orderService = new OrderService(
            $this->mockProductRepository,
            $this->mockOrderRepository,
            $this->mockCustomerRepository,
            $this->mockEventDispatcher
        );

        // Begin transaction mock setup
        $this->mockOrderRepository
            ->shouldReceive('beginTransaction')
            ->once()
            ->andReturnNull();

        $this->mockOrderRepository
            ->shouldReceive('commit')
            ->once()
            ->andReturnNull();

        $this->mockOrderRepository
            ->shouldReceive('rollback')
            ->zeroOrMoreTimes()
            ->andReturnNull();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testCreatesOrderWithValidItems(): void
    {
        $customer = new Customer([
            'id' => 100,
            'email' => 'shopper@example.com',
            'name' => 'Sarah Mitchell',
            'tier' => 'premium',
        ]);

        $product1 = new Product([
            'id' => 501,
            'sku' => 'WIDGET-RED-L',
            'name' => 'Red Widget Large',
            'price' => 2999,
            'inventory_count' => 50,
        ]);

        $product2 = new Product([
            'id' => 502,
            'sku' => 'GADGET-BLU-S',
            'name' => 'Blue Gadget Small',
            'price' => 4999,
            'inventory_count' => 30,
        ]);

        $this->mockCustomerRepository
            ->shouldReceive('findById')
            ->with(100)
            ->andReturn($customer);

        $this->mockProductRepository
            ->shouldReceive('findById')
            ->with(501)
            ->andReturn($product1);

        $this->mockProductRepository
            ->shouldReceive('findById')
            ->with(502)
            ->andReturn($product2);

        $this->mockProductRepository
            ->shouldReceive('decrementInventory')
            ->twice()
            ->andReturn(true);

        $this->mockOrderRepository
            ->shouldReceive('create')
            ->once()
            ->andReturnUsing(function ($orderData) {
                $order = new Order($orderData);
                $order->id = 1001;
                return $order;
            });

        $this->mockEventDispatcher
            ->shouldReceive('dispatch')
            ->with(Mockery::type(\App\Events\OrderCreated::class))
            ->once();

        $orderData = [
            'customer_id' => 100,
            'items' => [
                ['product_id' => 501, 'quantity' => 2, 'unit_price' => 2999],
                ['product_id' => 502, 'quantity' => 1, 'unit_price' => 4999],
            ],
            'shipping_address' => [
                'street' => '742 Evergreen Terrace',
                'city' => 'Springfield',
                'state' => 'IL',
                'zip' => '62701',
                'country' => 'US',
            ],
            'payment_method' => 'credit_card',
        ];

        $order = $this->orderService->createOrder($orderData);

        $this->assertInstanceOf(Order::class, $order);
        $this->assertEquals(100, $order->customer_id);
        $this->assertEquals(2, count($order->items));
        $this->assertEquals(10997, $order->total);
    }

    public function testRollsBackOnInsufficientInventory(): void
    {
        $customer = new Customer([
            'id' => 101,
            'email' => 'buyer@example.com',
            'name' => 'Tom Richards',
        ]);

        $product = new Product([
            'id' => 503,
            'sku' => 'PREMIUM-ITEM',
            'name' => 'Premium Widget',
            'price' => 9999,
            'inventory_count' => 2,
        ]);

        $this->mockCustomerRepository
            ->shouldReceive('findById')
            ->with(101)
            ->andReturn($customer);

        $this->mockProductRepository
            ->shouldReceive('findById')
            ->with(503)
            ->andReturn($product);

        $this->mockProductRepository
            ->shouldReceive('decrementInventory')
            ->once()
            ->andThrow(new InsufficientInventoryException('Not enough inventory for SKU PREMIUM-ITEM'));

        $this->mockOrderRepository
            ->shouldReceive('rollback')
            ->once();

        $this->mockEventDispatcher
            ->shouldReceive('dispatch')
            ->never();

        $orderData = [
            'customer_id' => 101,
            'items' => [
                ['product_id' => 503, 'quantity' => 10, 'unit_price' => 9999],
            ],
            'shipping_address' => [
                'street' => '123 Main St',
                'city' => 'Chicago',
                'state' => 'IL',
                'zip' => '60601',
            ],
        ];

        $this->expectException(InsufficientInventoryException::class);
        $this->orderService->createOrder($orderData);
    }
}
