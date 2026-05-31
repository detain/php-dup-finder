<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use Psr\Log\LoggerInterface;
use App\Domain\Orders\Entity\Order;
use App\Domain\Orders\Repository\OrderRepositoryInterface;
use App\Domain\Orders\Event\OrderPlacedEvent;
use App\Infrastructure\Messaging\EventDispatcher;

/**
 * Order processing service handling order lifecycle.
 * The LoggerInterface is manually injected here, duplicated across
 * all service classes that need logging.
 */
class OrderService
{
    private LoggerInterface $logger;
    private OrderRepositoryInterface $orderRepository;
    private EventDispatcher $eventDispatcher;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        EventDispatcher $eventDispatcher,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
    }

    public function placeOrder(Order $order): Order
    {
        $this->logger->info('Placing order', [
            'order_id' => $order->getId()?->toString(),
            'customer_id' => $order->getCustomerId()->toString(),
            'total_amount' => $order->getTotalAmount()->getAmount(),
        ]);

        try {
            $order->validate();

            if (!$order->getPaymentMethod()->isAuthorized()) {
                throw new PaymentNotAuthorizedException(
                    'Payment method is not authorized for this order amount'
                );
            }

            $savedOrder = $this->orderRepository->save($order);

            $this->eventDispatcher->dispatch(
                new OrderPlacedEvent($savedOrder)
            );

            $this->logger->info('Order placed successfully', [
                'order_id' => $savedOrder->getId()->toString(),
                'order_number' => $savedOrder->getOrderNumber(),
            ]);

            return $savedOrder;

        } catch (\Exception $e) {
            $this->logger->error('Failed to place order', [
                'customer_id' => $order->getCustomerId()->toString(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function cancelOrder(string $orderId, string $reason): Order
    {
        $this->logger->info('Cancelling order', [
            'order_id' => $orderId,
            'reason' => $reason,
        ]);

        $order = $this->orderRepository->findById($orderId);

        if ($order->isAlreadyShipped()) {
            throw new OrderAlreadyShippedException(
                'Cannot cancel an order that has already been shipped'
            );
        }

        $order->cancel($reason);
        $this->orderRepository->save($order);

        $this->logger->info('Order cancelled successfully', [
            'order_id' => $orderId,
        ]);

        return $order;
    }

    public function refundOrder(string $orderId, float $amount): Order
    {
        $this->logger->info('Processing order refund', [
            'order_id' => $orderId,
            'refund_amount' => $amount,
        ]);

        $order = $this->orderRepository->findById($orderId);

        if (!$order->isRefundable()) {
            throw new OrderNotRefundableException(
                'This order is not eligible for refund'
            );
        }

        if ($amount > $order->getTotalAmount()->getAmount()) {
            throw new RefundAmountExceedsOrderException(
                'Refund amount exceeds order total'
            );
        }

        $order->addRefund($amount);
        $this->orderRepository->save($order);

        $this->logger->info('Refund processed successfully', [
            'order_id' => $orderId,
            'refund_amount' => $amount,
            'remaining_amount' => $order->getTotalAmount()->getAmount() - $amount,
        ]);

        return $order;
    }
}
