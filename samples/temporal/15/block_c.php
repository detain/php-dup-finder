<?php
declare(strict_types=1);

namespace Shopify\Fulfilment\Service;

use Shopify\Fulfilment\Repository\FulfilmentOrderRepository;
use Shopify\Fulfilment\Repository\InventoryLevelRepository;
use Shopify\Fulfilment\Repository\ShippingLabelRepository;
use Shopify\Fulfilment\Entity\FulfilmentOrder;
use Shopify\Fulfilment\Entity\FulfilmentLineItem;
use Shopify\Fulfilment\Entity\ShippingLabel;
use Shopify\Fulfilment\Exception\FulfilmentException;
use Shopify\Fulfilment\Service\ShippingService;
use Shopify\Fulfilment\Service\NotificationService;
use Psr\Log\LoggerInterface;

final class FulfilmentService
{
    private FulfilmentOrderRepository $orderRepo;
    private InventoryLevelRepository $inventoryRepo;
    private ShippingLabelRepository $labelRepo;
    private ShippingService $shippingService;
    private NotificationService $notificationService;
    private LoggerInterface $logger;

    public function __construct(
        FulfilmentOrderRepository $orderRepo,
        InventoryLevelRepository $inventoryRepo,
        ShippingLabelRepository $labelRepo,
        ShippingService $shippingService,
        NotificationService $notificationService,
        LoggerInterface $logger
    ) {
        $this->orderRepo = $orderRepo;
        $this->inventoryRepo = $inventoryRepo;
        $this->labelRepo = $labelRepo;
        $this->shippingService = $shippingService;
        $this->notificationService = $notificationService;
        $this->logger = $logger;
    }

    public function acceptFulfilmentOrder(string $orderId): AcceptanceResult
    {
        $this->logger->info('Accepting fulfilment order', ['order_id' => $orderId]);

        $fulfilmentOrder = $this->orderRepo->findById($orderId);
        if ($fulfilmentOrder === null) {
            throw new FulfilmentException("Fulfilment order not found: {$orderId}");
        }

        if ($fulfilmentOrder->getStatus() !== 'pending') {
            throw new FulfilmentException("Order cannot be accepted in status: {$fulfilmentOrder->getStatus()}");
        }

        $this->orderRepo->updateStatus($orderId, 'accepted');

        $fulfilmentOrder->setAcceptedAt(new \DateTimeImmutable());
        $this->orderRepo->save($fulfilmentOrder);

        $this->logger->debug('Fulfilment order accepted', ['order_id' => $orderId]);

        return new AcceptanceResult([
            'success' => true,
            'order_id' => $orderId,
            'accepted_at' => $fulfilmentOrder->getAcceptedAt()->format('c')
        ]);
    }

    public function reserveInventory(string $orderId): ReservationResult
    {
        $fulfilmentOrder = $this->orderRepo->findById($orderId);
        if ($fulfilmentOrder === null) {
            throw new FulfilmentException("Fulfilment order not found: {$orderId}");
        }

        if ($fulfilmentOrder->getStatus() !== 'accepted') {
            throw new FulfilmentException("Inventory can only be reserved for accepted orders, status: {$fulfilmentOrder->getStatus()}");
        }

        $lineItems = $this->orderRepo->getLineItems($orderId);
        $reservedItems = [];

        foreach ($lineItems as $item) {
            $available = $this->inventoryRepo->getAvailableQuantity(
                $item['sku'],
                $item['warehouse_id']
            );

            if ($available < $item['quantity']) {
                throw new FulfilmentException(
                    "Insufficient inventory for SKU {$item['sku']}: required {$item['quantity']}, available {$available}"
                );
            }

            $reservationId = $this->inventoryRepo->reserveQuantity(
                $item['sku'],
                $item['warehouse_id'],
                $item['quantity'],
                $orderId
            );

            $reservedItems[] = [
                'sku' => $item['sku'],
                'quantity' => $item['quantity'],
                'reservation_id' => $reservationId
            ];
        }

        $this->orderRepo->updateStatus($orderId, 'inventory_reserved');
        $this->orderRepo->attachReservations($orderId, $reservedItems);

        $this->logger->info('Inventory reserved for fulfilment order', [
            'order_id' => $orderId,
            'items_reserved' => count($reservedItems)
        ]);

        return new ReservationResult([
            'success' => true,
            'order_id' => $orderId,
            'reserved_items' => $reservedItems
        ]);
    }

    public function purchaseShippingLabel(string $orderId, array $shippingOptions): LabelPurchaseResult
    {
        $fulfilmentOrder = $this->orderRepo->findById($orderId);
        if ($fulfilmentOrder === null) {
            throw new FulfilmentException("Fulfilment order not found: {$orderId}");
        }

        if ($fulfilmentOrder->getStatus() !== 'inventory_reserved') {
            throw new FulfilmentException("Must reserve inventory before purchasing label, status: {$fulfilmentOrder->getStatus()}");
        }

        $this->orderRepo->updateStatus($orderId, 'label_purchasing');

        try {
            $selectedOption = $shippingOptions[$fulfilmentOrder->getSelectedShippingMethod()] ?? current($shippingOptions);

            $labelData = $this->shippingService->purchaseLabel(
                $fulfilmentOrder->getShippingAddress(),
                $fulfilmentOrder->getLineItems(),
                $selectedOption
            );

            $shippingLabel = ShippingLabel::create([
                'order_id' => $orderId,
                'carrier' => $labelData['carrier'],
                'service' => $labelData['service'],
                'tracking_number' => $labelData['tracking_number'],
                'label_url' => $labelData['label_url'],
                'cost' => $labelData['cost'],
                'currency' => $labelData['currency'],
                'status' => 'purchased',
                'purchased_at' => new \DateTimeImmutable()
            ]);

            $savedLabel = $this->labelRepo->save($shippingLabel);
            $this->orderRepo->attachShippingLabel($orderId, $savedLabel->getId());

            $this->orderRepo->updateStatus($orderId, 'label_purchased');

            $this->logger->info('Shipping label purchased', [
                'order_id' => $orderId,
                'label_id' => $savedLabel->getId(),
                'tracking_number' => $labelData['tracking_number']
            ]);

            return new LabelPurchaseResult([
                'success' => true,
                'order_id' => $orderId,
                'label_id' => $savedLabel->getId(),
                'tracking_number' => $labelData['tracking_number'],
                'label_url' => $labelData['label_url']
            ]);

        } catch (\Throwable $e) {
            $this->orderRepo->updateStatus($orderId, 'label_purchase_failed');
            $this->logger->error('Label purchase failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function markAsShipped(string $orderId, string $trackingNumber): ShipmentResult
    {
        $fulfilmentOrder = $this->orderRepo->findById($orderId);
        if ($fulfilmentOrder === null) {
            throw new FulfilmentException("Fulfilment order not found: {$orderId}");
        }

        if ($fulfilmentOrder->getStatus() !== 'label_purchased') {
            throw new FulfilmentException("Must purchase label before shipping, status: {$fulfilmentOrder->getStatus()}");
        }

        $this->orderRepo->updateStatus($orderId, 'shipped');
        $this->orderRepo->updateTrackingNumber($orderId, $trackingNumber);
        $this->orderRepo->setShippedAt($orderId, new \DateTimeImmutable());

        $this->notificationService->notifyShipped(
            $fulfilmentOrder->getCustomerId(),
            $orderId,
            $trackingNumber
        );

        $this->logger->info('Fulfilment order marked as shipped', [
            'order_id' => $orderId,
            'tracking_number' => $trackingNumber
        ]);

        return new ShipmentResult([
            'success' => true,
            'order_id' => $orderId,
            'shipped_at' => (new \DateTimeImmutable())->format('c')
        ]);
    }
}
