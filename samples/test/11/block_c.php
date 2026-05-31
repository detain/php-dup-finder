<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Services\OrderProcessingService;
use App\Services\InventoryService;
use App\Services\PaymentService;
use App\Services\NotificationService;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Inventory;
use App\Exceptions\OrderProcessingException;
use App\Events\OrderPlacedEvent;
use App\Events\PaymentProcessedEvent;
use App\Events\InventoryReservedEvent;

final class OrderProcessingServiceTest extends TestCase
{
    private OrderProcessingService $orderService;
    private MockObject&InventoryService $inventoryService;
    private MockObject&PaymentService $paymentService;
    private MockObject&NotificationService $notificationService;

    protected function setUp(): void
    {
        $this->inventoryService = $this->createMock(InventoryService::class);
        $this->paymentService = $this->createMock(PaymentService::class);
        $this->notificationService = $this->createMock(NotificationService::class);

        $this->orderService = new OrderProcessingService(
            $this->inventoryService,
            $this->paymentService,
            $this->notificationService
        );

        $this->setupInventoryMockBehavior();
        $this->setupPaymentMockBehavior();
        $this->setupNotificationMockBehavior();
    }

    private function setupInventoryMockBehavior(): void
    {
        $this->inventoryService->method('reserve')
            ->willReturnCallback(function (int $productId, int $quantity) {
                if ($quantity <= 0) {
                    throw new \InvalidArgumentException('Quantity must be positive');
                }

                if ($productId === 999) {
                    throw new OrderProcessingException('Insufficient inventory');
                }

                return new Inventory([
                    'product_id' => $productId,
                    'quantity_reserved' => $quantity,
                    'reservation_id' => 'res_' . uniqid(),
                    'expires_at' => date('Y-m-d H:i:s', strtotime('+15 minutes')),
                ]);
            });

        $this->inventoryService->method('release')
            ->willReturn(true);

        $this->inventoryService->method('confirm')
            ->willReturn(true);

        $this->inventoryService->method('checkAvailability')
            ->willReturnCallback(function (int $productId, int $quantity) {
                if ($productId === 999) {
                    return false;
                }
                return $quantity <= 100;
            });
    }

    private function setupPaymentMockBehavior(): void
    {
        $this->paymentService->method('process')
            ->willReturnCallback(function (Order $order, float $amount, string $method) {
                if ($amount <= 0) {
                    throw new OrderProcessingException('Invalid payment amount');
                }

                if ($method === 'invalid') {
                    throw new OrderProcessingException('Payment method not accepted');
                }

                return new Payment([
                    'order_id' => $order->id,
                    'amount' => $amount,
                    'method' => $method,
                    'status' => 'completed',
                    'transaction_id' => 'txn_' . bin2hex(random_bytes(8)),
                    'processed_at' => new \DateTimeImmutable(),
                ]);
            });

        $this->paymentService->method('refund')
            ->willReturnCallback(function (string $transactionId, float $amount) {
                if (empty($transactionId)) {
                    throw new \InvalidArgumentException('Transaction ID required');
                }

                return [
                    'refund_id' => 'ref_' . uniqid(),
                    'transaction_id' => $transactionId,
                    'amount' => $amount,
                    'status' => 'completed',
                ];
            });
    }

    private function setupNotificationMockBehavior(): void
    {
        $this->notificationService->method('sendOrderConfirmation')
            ->willReturn(true);

        $this->notificationService->method('sendPaymentConfirmation')
            ->willReturn(true);

        $this->notificationService->method('sendShippingNotification')
            ->willReturn(true);

        $this->notificationService->method('sendOrderCancellation')
            ->willReturn(true);
    }

    private function createTestOrder(int $id = 1, string $status = 'pending'): Order
    {
        $order = new Order();
        $order->id = $id;
        $order->customer_id = 100;
        $order->status = $status;
        $order->total_amount = 199.99;
        $order->currency = 'USD';
        $order->items = [
            ['product_id' => 1, 'quantity' => 2, 'price' => 49.99],
            ['product_id' => 2, 'quantity' => 1, 'price' => 99.99],
        ];
        $order->payment_method = 'credit_card';
        $order->shipping_address = [
            'street' => '123 Main St',
            'city' => 'New York',
            'state' => 'NY',
            'zip' => '10001',
            'country' => 'US',
        ];
        $order->created_at = new \DateTimeImmutable();
        $order->updated_at = new \DateTimeImmutable();
        return $order;
    }

    public function testProcessOrderSuccessfully(): void
    {
        $order = $this->createTestOrder(1, 'pending');

        $result = $this->orderService->processOrder($order);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('order_id', $result);
        $this->assertArrayHasKey('payment', $result);
        $this->assertArrayHasKey('inventory_reservations', $result);
        $this->assertArrayHasKey('notifications_sent', $result);

        $this->assertEquals(1, $result['order_id']);
        $this->assertEquals('completed', $result['payment']['status']);
        $this->assertTrue($result['inventory_reservations'][0]['reserved']);
        $this->assertCount(1, $result['notifications_sent']);
    }

    public function testProcessOrderWithInsufficientInventory(): void
    {
        $order = $this->createTestOrder(2, 'pending');
        $order->items = [
            ['product_id' => 999, 'quantity' => 5, 'price' => 99.99],
        ];

        $this->expectException(OrderProcessingException::class);
        $this->expectExceptionMessage('Insufficient inventory');

        $this->orderService->processOrder($order);
    }

    public function testProcessOrderWithPaymentFailure(): void
    {
        $order = $this->createTestOrder(3, 'pending');
        $order->payment_method = 'invalid';

        $this->expectException(OrderProcessingException::class);
        $this->expectExceptionMessage('Payment method not accepted');

        $this->orderService->processOrder($order);
    }

    public function testCancelOrderReleasesInventory(): void
    {
        $order = $this->createTestOrder(4, 'processing');

        $this->inventoryService->expects($this->once())
            ->method('release')
            ->with($this->isType('array'));

        $result = $this->orderService->cancelOrder($order, 'customer_request');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('order_id', $result);
        $this->assertArrayHasKey('cancelled', $result);
        $this->assertTrue($result['cancelled']);
    }

    public function testRefundOrderReversesPayment(): void
    {
        $order = $this->createTestOrder(5, 'completed');

        $this->paymentService->expects($this->once())
            ->method('refund')
            ->with(
                $this->isType('string'),
                $this->isType('float')
            );

        $result = $this->orderService->refundOrder($order, 0.5);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('order_id', $result);
        $this->assertArrayHasKey('refund', $result);
        $this->assertEquals(0.5, $result['refund']['amount']);
    }

    public function testPartialRefundCalculation(): void
    {
        $order = $this->createTestOrder(6, 'completed');
        $order->total_amount = 100.00;

        $result = $this->orderService->refundOrder($order, 0.25);

        $this->assertEquals(25.00, $result['refund']['amount']);
    }

    public function testOrderStatusTransitions(): void
    {
        $order = $this->createTestOrder(7, 'pending');

        $this->assertEquals('pending', $order->status);

        $order->status = 'processing';
        $this->assertEquals('processing', $order->status);

        $order->status = 'shipped';
        $this->assertEquals('shipped', $order->status);
    }

    public function testInventoryReservationExpiry(): void
    {
        $order = $this->createTestOrder(8, 'pending');

        $result = $this->orderService->processOrder($order);

        $this->assertArrayHasKey('inventory_reservations', $result);
        $this->assertArrayHasKey('expires_at', $result['inventory_reservations'][0]);

        $expiresAt = new \DateTimeImmutable($result['inventory_reservations'][0]['expires_at']);
        $now = new \DateTimeImmutable();

        $this->assertGreaterThan($now, $expiresAt);
    }

    public function testNotificationServiceCalledOnOrderPlacement(): void
    {
        $order = $this->createTestOrder(9, 'pending');

        $this->notificationService->expects($this->once())
            ->method('sendOrderConfirmation')
            ->with(
                $this->isType('int'),
                $this->isType('array')
            );

        $this->orderService->processOrder($order);
    }

    public function testNotificationServiceCalledOnPaymentSuccess(): void
    {
        $order = $this->createTestOrder(10, 'pending');

        $this->notificationService->expects($this->once())
            ->method('sendPaymentConfirmation')
            ->with(
                $this->isType('int'),
                $this->isType('float')
            );

        $this->orderService->processOrder($order);
    }
}
