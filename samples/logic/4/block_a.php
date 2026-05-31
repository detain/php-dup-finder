<?php

declare(strict_types=1);

namespace App\OrderWorkflow;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Event\OrderStatusChangedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class OrderStatusService
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function submitOrder(int $orderId): Order
    {
        $order = $this->orderRepository->findById($orderId);

        if ($order === null) {
            throw new \RuntimeException('Order not found');
        }

        if ($order->getStatus() !== 'draft') {
            throw new \InvalidArgumentException('Only draft orders can be submitted');
        }

        if (empty($order->getItems())) {
            throw new \InvalidArgumentException('Order must have at least one item');
        }

        if ($order->getCustomerId() === null) {
            throw new \InvalidArgumentException('Order must have a customer assigned');
        }

        $order->setStatus('submitted');
        $order->setSubmittedAt(new \DateTimeImmutable());

        $this->orderRepository->save($order);

        $this->eventDispatcher->dispatch(
            new OrderStatusChangedEvent($order, 'draft', 'submitted'),
            OrderStatusChangedEvent::NAME
        );

        $this->logger->info('Order submitted', [
            'order_id' => $orderId,
        ]);

        return $order;
    }

    public function processOrder(int $orderId): Order
    {
        $order = $this->orderRepository->findById($orderId);

        if ($order === null) {
            throw new \RuntimeException('Order not found');
        }

        if ($order->getStatus() !== 'submitted') {
            throw new \InvalidArgumentException('Only submitted orders can be processed');
        }

        if ($order->getPaymentStatus() !== 'paid') {
            throw new \InvalidArgumentException('Order must be paid before processing');
        }

        $order->setStatus('processing');
        $order->setProcessedAt(new \DateTimeImmutable());

        $this->orderRepository->save($order);

        $this->eventDispatcher->dispatch(
            new OrderStatusChangedEvent($order, 'submitted', 'processing'),
            OrderStatusChangedEvent::NAME
        );

        $this->logger->info('Order processing started', [
            'order_id' => $orderId,
        ]);

        return $order;
    }

    public function shipOrder(int $orderId): Order
    {
        $order = $this->orderRepository->findById($orderId);

        if ($order === null) {
            throw new \RuntimeException('Order not found');
        }

        if ($order->getStatus() !== 'processing') {
            throw new \InvalidArgumentException('Only processing orders can be shipped');
        }

        if (empty($order->getShippingAddress())) {
            throw new \InvalidArgumentException('Order must have a shipping address');
        }

        if ($order->getItems() === []) {
            throw new \InvalidArgumentException('Order must have items to ship');
        }

        $order->setStatus('shipped');
        $order->setShippedAt(new \DateTimeImmutable());

        $this->orderRepository->save($order);

        $this->eventDispatcher->dispatch(
            new OrderStatusChangedEvent($order, 'processing', 'shipped'),
            OrderStatusChangedEvent::NAME
        );

        $this->logger->info('Order shipped', [
            'order_id' => $orderId,
        ]);

        return $order;
    }

    public function deliverOrder(int $orderId): Order
    {
        $order = $this->orderRepository->findById($orderId);

        if ($order === null) {
            throw new \RuntimeException('Order not found');
        }

        if ($order->getStatus() !== 'shipped') {
            throw new \InvalidArgumentException('Only shipped orders can be delivered');
        }

        $order->setStatus('delivered');
        $order->setDeliveredAt(new \DateTimeImmutable());

        $this->orderRepository->save($order);

        $this->eventDispatcher->dispatch(
            new OrderStatusChangedEvent($order, 'shipped', 'delivered'),
            OrderStatusChangedEvent::NAME
        );

        $this->logger->info('Order delivered', [
            'order_id' => $orderId,
        ]);

        return $order;
    }

    public function cancelOrder(int $orderId, string $reason): Order
    {
        $order = $this->orderRepository->findById($orderId);

        if ($order === null) {
            throw new \RuntimeException('Order not found');
        }

        if (in_array($order->getStatus(), ['delivered', 'cancelled', 'refunded'], true)) {
            throw new \InvalidArgumentException('Cannot cancel delivered, cancelled, or refunded orders');
        }

        if ($order->getStatus() === 'shipped' && !$order->canCancelBeforeDelivery()) {
            throw new \InvalidArgumentException('Order has already been shipped and cannot be cancelled');
        }

        $order->setStatus('cancelled');
        $order->setCancelledAt(new \DateTimeImmutable());
        $order->setCancellationReason($reason);

        $this->orderRepository->save($order);

        $this->eventDispatcher->dispatch(
            new OrderStatusChangedEvent($order, $order->getStatus(), 'cancelled'),
            OrderStatusChangedEvent::NAME
        );

        $this->logger->info('Order cancelled', [
            'order_id' => $orderId,
            'reason' => $reason,
        ]);

        return $order;
    }
}
