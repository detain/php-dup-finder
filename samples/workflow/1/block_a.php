<?php
declare(strict_types=1);

namespace App\Order\Workflow;

use App\Domain\Entity\Order;
use App\Domain\Entity\PaymentTransaction;
use App\Domain\Service\PaymentGatewayInterface;
use App\Domain\Service\InventoryServiceInterface;
use App\Domain\Service\NotificationServiceInterface;
use App\Domain\Repository\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class OrderFulfillmentWorkflow
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private PaymentGatewayInterface $paymentGateway,
        private InventoryServiceInterface $inventoryService,
        private NotificationServiceInterface $notificationService,
        private LoggerInterface $logger,
    ) {}

    public function processOrder(string $orderId): void
    {
        $order = $this->orderRepository->findById($orderId);
        if ($order === null) {
            throw new \RuntimeException("Order not found: {$orderId}");
        }

        $this->logger->info('Starting order fulfillment workflow', ['order_id' => $orderId]);

        $this->validateOrder($order);

        $this->authorizePayment($order);

        $this->reserveInventory($order);

        $this->updateOrderStatus($order, 'processing');

        $this->sendOrderConfirmation($order);

        $this->recordAuditEvent($order, 'order_processed');

        $this->logger->info('Order fulfillment workflow completed', ['order_id' => $orderId]);
    }

    private function validateOrder(Order $order): void
    {
        if ($order->getStatus() !== 'pending') {
            throw new \RuntimeException("Order {$order->getId()} is not in pending status");
        }

        if (count($order->getLineItems()) === 0) {
            throw new \RuntimeException("Order {$order->getId()} has no line items");
        }

        foreach ($order->getLineItems() as $item) {
            if ($item->getQuantity() <= 0) {
                throw new \RuntimeException("Invalid quantity for item {$item->getId()}");
            }
        }

        $this->logger->debug('Order validation passed', ['order_id' => $order->getId()->toString()]);
    }

    private function authorizePayment(Order $order): void
    {
        $transaction = $this->paymentGateway->authorize(
            $order->getCustomerId(),
            $order->getTotalAmount(),
            $order->getCurrency()
        );

        if (!$transaction->isSuccessful()) {
            $this->recordAuditEvent($order, 'payment_authorization_failed', [
                'reason' => $transaction->getFailureMessage(),
            ]);
            throw new \RuntimeException("Payment authorization failed: {$transaction->getFailureMessage()}");
        }

        $order->setPaymentTransactionId($transaction->getId());
        $this->recordAuditEvent($order, 'payment_authorized', ['transaction_id' => $transaction->getId()]);

        $this->logger->debug('Payment authorized', [
            'order_id' => $order->getId()->toString(),
            'transaction_id' => $transaction->getId(),
        ]);
    }

    private function reserveInventory(Order $order): void
    {
        foreach ($order->getLineItems() as $item) {
            $result = $this->inventoryService->reserveStock(
                $item->getProductId(),
                $item->getQuantity(),
                $order->getId()->toString()
            );

            if (!$result->isSuccessful()) {
                $this->releasePayment($order);
                $this->recordAuditEvent($order, 'inventory_reservation_failed', [
                    'product_id' => $item->getProductId()->toString(),
                    'reason' => $result->getMessage(),
                ]);
                throw new \RuntimeException("Inventory reservation failed: {$result->getMessage()}");
            }

            $this->recordAuditEvent($order, 'inventory_reserved', [
                'product_id' => $item->getProductId()->toString(),
                'quantity' => $item->getQuantity(),
            ]);
        }

        $this->logger->debug('Inventory reserved', ['order_id' => $order->getId()->toString()]);
    }

    private function sendOrderConfirmation(Order $order): void
    {
        $this->notificationService->send(
            $order->getCustomerId(),
            'order_confirmation',
            [
                'order_id' => $order->getId()->toString(),
                'order_number' => $order->getOrderNumber(),
                'total' => $order->getTotalAmount()->getAmount(),
                'currency' => $order->getCurrency()->code(),
            ]
        );

        $this->recordAuditEvent($order, 'confirmation_sent');

        $this->logger->debug('Order confirmation sent', ['order_id' => $order->getId()->toString()]);
    }

    private function releasePayment(Order $order): void
    {
        if ($order->getPaymentTransactionId() !== null) {
            $this->paymentGateway->void($order->getPaymentTransactionId());
        }
    }

    private function updateOrderStatus(Order $order, string $status): void
    {
        $order->setStatus($status);
        $order->setUpdatedAt(new \DateTimeImmutable());
        $this->orderRepository->save($order);
    }

    private function recordAuditEvent(Order $order, string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'order_id' => $order->getId()->toString(),
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }
}
