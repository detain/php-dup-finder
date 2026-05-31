<?php

declare(strict_types=1);

namespace App\OrderProcessing;

use App\Entity\CustomerOrder;
use App\Repository\OrderRepository;
use App\Service\InventoryService;
use App\Service\PaymentGateway;
use App\Event\OrderShippedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class OrderFulfillmentService
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly InventoryService $inventoryService,
        private readonly PaymentGateway $paymentGateway,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function processOrder(int $orderId): CustomerOrder
    {
        $order = $this->orderRepository->findById($orderId);

        if ($order === null) {
            throw new \RuntimeException("Order {$orderId} not found");
        }

        if ($order->getStatus() !== 'pending') {
            throw new \RuntimeException("Order {$orderId} cannot be processed - invalid status");
        }

        $items = $order->getItems();
        foreach ($items as $item) {
            $available = $this->inventoryService->checkAvailability(
                $item->getProductId(),
                $item->getQuantity()
            );

            if (!$available) {
                $this->logger->warning('Insufficient inventory for order', [
                    'order_id' => $orderId,
                    'product_id' => $item->getProductId(),
                    'requested' => $item->getQuantity(),
                ]);
                throw new \RuntimeException("Insufficient inventory for product {$item->getProductId()}");
            }
        }

        foreach ($items as $item) {
            $this->inventoryService->reserveStock(
                $item->getProductId(),
                $item->getQuantity()
            );
        }

        $transactionId = $this->paymentGateway->charge(
            $order->getCustomerId(),
            $order->getTotalAmount(),
            $order->getCurrency()
        );

        if ($transactionId === null) {
            foreach ($items as $item) {
                $this->inventoryService->releaseStock(
                    $item->getProductId(),
                    $item->getQuantity()
                );
            }
            throw new \RuntimeException("Payment processing failed for order {$orderId}");
        }

        $order->setStatus('processing');
        $order->setTransactionId($transactionId);
        $order->setProcessedAt(new \DateTimeImmutable());
        $this->orderRepository->save($order);

        $this->eventDispatcher->dispatch(
            new OrderShippedEvent($order),
            OrderShippedEvent::NAME
        );

        $this->logger->info('Order processed successfully', [
            'order_id' => $orderId,
            'transaction_id' => $transactionId,
        ]);

        return $order;
    }
}
