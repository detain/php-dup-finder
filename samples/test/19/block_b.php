<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use App\Services\EventDispatcher;
use App\Events\OrderCreated;
use App\Events\OrderPaid;
use App\Events\OrderShipped;
use App\Events\OrderDelivered;
use App\Events\OrderCancelled;
use App\Events\OrderRefunded;
use App\Listeners\SendOrderConfirmationListener;
use App\Listeners\UpdateInventoryListener;
use App\Listeners\SendShippingNotificationListener;
use App\Listeners\SendDeliveryNotificationListener;
use App\Listeners\ProcessRefundListener;
use App\Listeners\LogOrderEventListener;
use Mockery;
use Mockery\MockInterface;

class OrderEventDispatcherTest extends TestCase
{
    private EventDispatcher $dispatcher;
    private MockInterface $mailService;
    private MockInterface $inventoryService;
    private MockInterface $shippingService;
    private MockInterface $logger;
    private MockInterface $refundService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailService = Mockery::mock(\App\Services\MailService::class);
        $this->inventoryService = Mockery::mock(\App\Services\InventoryService::class);
        $this->shippingService = Mockery::mock(\App\Services\ShippingService::class);
        $this->logger = Mockery::mock(\App\Services\Logger::class);
        $this->refundService = Mockery::mock(\App\Services\RefundService::class);

        $this->dispatcher = new EventDispatcher();

        // Register event listeners
        $this->dispatcher->addListener(
            OrderCreated::class,
            new SendOrderConfirmationListener($this->mailService)
        );

        $this->dispatcher->addListener(
            OrderCreated::class,
            new UpdateInventoryListener($this->inventoryService)
        );

        $this->dispatcher->addListener(
            OrderCreated::class,
            new LogOrderEventListener($this->logger)
        );

        $this->dispatcher->addListener(
            OrderPaid::class,
            new UpdateInventoryListener($this->inventoryService)
        );

        $this->dispatcher->addListener(
            OrderShipped::class,
            new SendShippingNotificationListener($this->mailService)
        );

        $this->dispatcher->addListener(
            OrderDelivered::class,
            new SendDeliveryNotificationListener($this->mailService)
        );

        $this->dispatcher->addListener(
            OrderRefunded::class,
            new ProcessRefundListener($this->refundService)
        );

        $this->dispatcher->addListener(
            OrderCancelled::class,
            new UpdateInventoryListener($this->inventoryService, true)
        );

        $this->dispatcher->addListener(
            OrderCancelled::class,
            new LogOrderEventListener($this->logger)
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testDispatchesOrderCreatedEvent(): void
    {
        $order = [
            'id' => 100,
            'order_number' => 'ORD-001',
            'customer_email' => 'customer@example.com',
            'total' => 9999,
        ];

        $this->mailService
            ->shouldReceive('send')
            ->with(
                'order_confirmation',
                Mockery::type(\App\Mail\Mailable::class),
                'customer@example.com'
            )
            ->once();

        $this->inventoryService
            ->shouldReceive('decrement')
            ->with(Mockery::type('array'))
            ->once();

        $this->logger
            ->shouldReceive('info')
            ->once();

        $event = new OrderCreated($order);
        $this->dispatcher->dispatch($event);
    }

    public function testDispatchesOrderShippedEvent(): void
    {
        $order = [
            'id' => 100,
            'order_number' => 'ORD-001',
            'customer_email' => 'customer@example.com',
        ];

        $trackingNumber = '1Z999AA10123456784';

        $this->mailService
            ->shouldReceive('send')
            ->with(
                'shipping_notification',
                Mockery::type(\App\Mail\Mailable::class),
                'customer@example.com'
            )
            ->once();

        $event = new OrderShipped($order, $trackingNumber);
        $this->dispatcher->dispatch($event);
    }

    public function testDispatchesOrderDeliveredEvent(): void
    {
        $order = [
            'id' => 100,
            'order_number' => 'ORD-001',
            'customer_email' => 'customer@example.com',
        ];

        $this->mailService
            ->shouldReceive('send')
            ->with(
                'delivery_notification',
                Mockery::type(\App\Mail\Mailable::class),
                'customer@example.com'
            )
            ->once();

        $event = new OrderDelivered($order);
        $this->dispatcher->dispatch($event);
    }

    public function testDispatchesOrderRefundedEvent(): void
    {
        $order = [
            'id' => 100,
            'order_number' => 'ORD-001',
            'total' => 9999,
        ];

        $this->refundService
            ->shouldReceive('process')
            ->with(100, 9999)
            ->once();

        $event = new OrderRefunded($order, 9999);
        $this->dispatcher->dispatch($event);
    }

    public function testDispatchesOrderCancelledEvent(): void
    {
        $order = [
            'id' => 100,
            'order_number' => 'ORD-001',
            'items' => [
                ['product_id' => 1, 'quantity' => 2],
                ['product_id' => 2, 'quantity' => 1],
            ],
        ];

        $this->inventoryService
            ->shouldReceive('restore')
            ->with(Mockery::type('array'))
            ->once();

        $this->logger
            ->shouldReceive('info')
            ->once();

        $event = new OrderCancelled($order, 'Customer requested cancellation');
        $this->dispatcher->dispatch($event);
    }

    public function testListenersAreCalledInRegistrationOrder(): void
    {
        $callOrder = [];

        $listener1 = new class($callOrder) {
            public function __construct(private array &$order) {}
            public function __invoke($event) { $this->order[] = 'listener1'; }
        };

        $listener2 = new class($callOrder) {
            public function __construct(private array &$order) {}
            public function __invoke($event) { $this->order[] = 'listener2'; }
        };

        $this->dispatcher->addListener(OrderPaid::class, $listener1);
        $this->dispatcher->addListener(OrderPaid::class, $listener2);

        $event = new OrderPaid(['id' => 1]);
        $this->dispatcher->dispatch($event);

        $this->assertEquals(['listener1', 'listener2'], $callOrder);
    }
}
