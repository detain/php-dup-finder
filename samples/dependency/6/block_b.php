<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment;

use Psr\Log\LoggerInterface;

/**
 * Fulfillment processing service.
 * The LoggerInterface is manually injected here, duplicated from
 * InventoryService and other services.
 */
class FulfillmentService
{
    private LoggerInterface $logger;
    private OrderRepositoryInterface $orderRepository;
    private WarehouseService $warehouseService;
    private ShipmentService $shipmentService;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        WarehouseService $warehouseService,
        ShipmentService $shipmentService,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->warehouseService = $warehouseService;
        $this->shipmentService = $shipmentService;
        $this->logger = $logger;
    }

    public function processOrder(string $orderId): FulfillmentResult
    {
        $this->logger->info('Processing order fulfillment', [
            'order_id' => $orderId,
        ]);

        $order = $this->orderRepository->findById($orderId);

        if ($order === null) {
            $this->logger->error('Order not found for fulfillment', [
                'order_id' => $orderId,
            ]);
            throw new OrderNotFoundException("Order not found: {$orderId}");
        }

        if (!$order->canBeFulfilled()) {
            $this->logger->warning('Order cannot be fulfilled', [
                'order_id' => $orderId,
                'status' => $order->getStatus()->getValue(),
            ]);
            throw new OrderNotFulfillableException(
                "Order with status {$order->getStatus()->getValue()} cannot be fulfilled"
            );
        }

        $warehouseAssignment = $this->warehouseService->assignWarehouse($order);

        $this->logger->info('Warehouse assigned', [
            'order_id' => $orderId,
            'warehouse_id' => $warehouseAssignment->getWarehouseId(),
        ]);

        $shipment = $this->shipmentService->createShipment(
            orderId: $orderId,
            warehouseId: $warehouseAssignment->getWarehouseId(),
            items: $order->getItems(),
            shippingMethod: $order->getShippingMethod(),
        );

        $this->logger->info('Shipment created', [
            'order_id' => $orderId,
            'shipment_id' => $shipment->getId(),
        ]);

        $order->markAsFulfilled($shipment->getId());
        $this->orderRepository->save($order);

        return new FulfillmentResult(
            success: true,
            orderId: $orderId,
            shipmentId: $shipment->getId(),
            warehouseId: $warehouseAssignment->getWarehouseId(),
        );
    }

    public function processReturn(string $orderId, array $items, string $reason): ReturnResult
    {
        $this->logger->info('Processing return', [
            'order_id' => $orderId,
            'items' => count($items),
            'reason' => $reason,
        ]);

        $order = $this->orderRepository->findById($orderId);

        if ($order === null) {
            throw new OrderNotFoundException("Order not found: {$orderId}");
        }

        if (!$order->isReturnable()) {
            $this->logger->warning('Order not returnable', [
                'order_id' => $orderId,
                'status' => $order->getStatus()->getValue(),
            ]);
            throw new OrderNotReturnableException(
                "Order cannot be returned in its current status"
            );
        }

        $returnAuthorization = $this->generateReturnAuthorization($order, $items, $reason);

        $this->logger->info('Return authorized', [
            'order_id' => $orderId,
            'return_id' => $returnAuthorization->getId(),
        ]);

        return new ReturnResult(
            success: true,
            returnId: $returnAuthorization->getId(),
            returnLabel: $returnAuthorization->getReturnLabel(),
            estimatedRefund: $returnAuthorization->getEstimatedRefund(),
        );
    }

    public function checkFulfillmentStatus(string $orderId): FulfillmentStatus
    {
        $this->logger->debug('Checking fulfillment status', [
            'order_id' => $orderId,
        ]);

        $order = $this->orderRepository->findById($orderId);

        if ($order === null) {
            throw new OrderNotFoundException("Order not found: {$orderId}");
        }

        return new FulfillmentStatus(
            orderId: $orderId,
            status: $order->getStatus()->getValue(),
            shipmentId: $order->getShipmentId(),
            trackingNumber: $order->getTrackingNumber(),
            estimatedDelivery: $order->getEstimatedDelivery(),
        );
    }

    private function generateReturnAuthorization(
        Order $order,
        array $items,
        string $reason
    ): ReturnAuthorization {
        $returnId = 'RET-' . bin2hex(random_bytes(8));
        $estimatedRefund = $this->calculateReturnRefund($order, $items);

        return new ReturnAuthorization(
            id: $returnId,
            orderId: $order->getId(),
            items: $items,
            reason: $reason,
            returnLabel: $this->generateReturnLabel($order, $returnId),
            estimatedRefund: $estimatedRefund,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function calculateReturnRefund(Order $order, array $items): float
    {
        return array_reduce(
            $items,
            fn($total, $item) => $total + ($item['quantity'] * $item['unit_price']),
            0.0
        );
    }

    private function generateReturnLabel(Order $order, string $returnId): string
    {
        return "https://shipping.example.com/returns/{$returnId}/label";
    }
}
