<?php
declare(strict_types=1);

namespace App\Domain\Fulfillment\EventHandler;

use App\Entity\ReturnRequest;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\RefundService;
use App\Service\InventoryService;
use App\Service\CustomerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class ReturnInitiatedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly RefundService $refundService,
        private readonly InventoryService $inventoryService,
        private readonly CustomerService $customerService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(ReturnRequest $returnRequest): void
    {
        $this->logger->info('Processing return initiated event', [
            'return_id' => $returnRequest->getId(),
            'order_id' => $returnRequest->getOrderId(),
            'customer_id' => $returnRequest->getCustomerId(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->validateReturnEligibility($returnRequest);
            $this->allocateReturnInventory($returnRequest);
            $this->reserveRefundAmount($returnRequest);
            $this->notifyWarehouse($returnRequest);
            $this->updateCustomerAccount($returnRequest);
            $this->recordReturnAnalytics($returnRequest);
            $this->createAuditEntry($returnRequest);
            $this->triggerReturnShipping($returnRequest);

            $this->entityManager->commit();

            $this->logger->info('Return initiated event processed', [
                'return_id' => $returnRequest->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process return initiated event', [
                'return_id' => $returnRequest->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function validateReturnEligibility(ReturnRequest $returnRequest): void
    {
        $order = $this->entityManager
            ->getRepository(\App\Entity\Order::class)
            ->find($returnRequest->getOrderId());

        if ($order === null) {
            throw new \RuntimeException('Order not found: ' . $returnRequest->getOrderId());
        }

        $orderDate = $order->getCreatedAt();
        $returnWindow = (new \DateTimeImmutable())->modify('-30 days');

        if ($orderDate < $returnWindow) {
            throw new \DomainException('Return window has expired');
        }

        foreach ($returnRequest->getItems() as $item) {
            $originalOrderItem = $this->entityManager
                ->getRepository(\App\Entity\OrderItem::class)
                ->findOneBy(['orderId' => $order->getId(), 'productId' => $item->getProductId()]);

            if ($originalOrderItem === null) {
                throw new \RuntimeException('Item not found in original order');
            }

            if ($item->getQuantity() > $originalOrderItem->getQuantity()) {
                throw new \DomainException('Return quantity exceeds original purchase');
            }
        }

        $this->logger->debug('Validated return eligibility', [
            'return_id' => $returnRequest->getId(),
        ]);
    }

    private function allocateReturnInventory(ReturnRequest $returnRequest): void
    {
        foreach ($returnRequest->getItems() as $item) {
            $returnWarehouse = $this->entityManager
                ->getRepository(\App\Entity\Warehouse::class)
                ->findDefaultForReturns();

            $inventory = $this->inventoryService->getOrCreateInventory(
                $item->getProductId(),
                $returnWarehouse->getId()
            );

            $inventory->setQuantity($inventory->getQuantity() + $item->getQuantity());
            $inventory->setQualityStatus('pending_inspection');
            $inventory->setLastUpdated(new \DateTimeImmutable());

            $this->entityManager->persist($inventory);

            $allocation = new \App\Entity\ReturnInventoryAllocation();
            $allocation->setReturnRequest($returnRequest);
            $allocation->setProductId($item->getProductId());
            $allocation->setWarehouseId($returnWarehouse->getId());
            $allocation->setQuantity($item->getQuantity());
            $allocation->setStatus('allocated');
            $allocation->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($allocation);

            $this->logger->debug('Allocated return inventory', [
                'return_id' => $returnRequest->getId(),
                'product_id' => $item->getProductId(),
                'quantity' => $item->getQuantity(),
            ]);
        }
    }

    private function reserveRefundAmount(ReturnRequest $returnRequest): void
    {
        $order = $this->entityManager
            ->getRepository(\App\Entity\Order::class)
            ->find($returnRequest->getOrderId());

        $totalRefund = 0;
        foreach ($returnRequest->getItems() as $item) {
            $orderItem = $this->entityManager
                ->getRepository(\App\Entity\OrderItem::class)
                ->findOneBy(['orderId' => $order->getId(), 'productId' => $item->getProductId()]);

            $itemRefund = $orderItem->getUnitPrice() * $item->getQuantity();
            $totalRefund += $itemRefund;
        }

        if ($returnRequest->getReason() === 'defective') {
            $totalRefund += $returnRequest->getShippingRefund();
        }

        $reservation = new \App\Entity\RefundReservation();
        $reservation->setReturnRequest($returnRequest);
        $reservation->setAmount($totalRefund);
        $reservation->setStatus('reserved');
        $reservation->setExpiresAt(
            (new \DateTimeImmutable())->modify('+14 days')
        );
        $reservation->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($reservation);

        $this->refundService->reserveAmount(
            $returnRequest->getCustomerId(),
            $totalRefund,
            'return_' . $returnRequest->getId()
        );

        $this->logger->debug('Reserved refund amount', [
            'return_id' => $returnRequest->getId(),
            'amount' => $totalRefund,
        ]);
    }

    private function notifyWarehouse(ReturnRequest $returnRequest): void
    {
        $warehouse = $this->entityManager
            ->getRepository(\App\Entity\Warehouse::class)
            ->findDefaultForReturns();

        $items = [];
        foreach ($returnRequest->getItems() as $item) {
            $product = $this->entityManager
                ->getRepository(\App\Entity\Product::class)
                ->find($item->getProductId());

            $items[] = [
                'product_id' => $item->getProductId(),
                'product_name' => $product?->getName() ?? 'Unknown',
                'quantity' => $item->getQuantity(),
                'reason' => $returnRequest->getReason(),
            ];
        }

        $notification = new \App\Entity\WarehouseNotification();
        $notification->setType('return_expected');
        $notification->setWarehouse($warehouse);
        $notification->setPayload([
            'return_id' => $returnRequest->getId(),
            'items' => $items,
            'rma_number' => $returnRequest->getRmaNumber(),
            'priority' => 'normal',
        ]);
        $notification->setStatus('pending');
        $notification->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($notification);

        $this->queueService->publish('warehouse.returns', [
            'warehouse_id' => $warehouse->getId(),
            'return_id' => $returnRequest->getId(),
            'rma_number' => $returnRequest->getRmaNumber(),
            'items' => $items,
        ]);

        $this->logger->debug('Notified warehouse of incoming return', [
            'return_id' => $returnRequest->getId(),
            'warehouse_id' => $warehouse->getId(),
        ]);
    }

    private function updateCustomerAccount(ReturnRequest $returnRequest): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($returnRequest->getCustomerId());

        if ($customer === null) {
            throw new \RuntimeException('Customer not found');
        }

        $customer->setReturnRequestsCount($customer->getReturnRequestsCount() + 1);

        if ($returnRequest->getReason() === 'defective') {
            $customer->setDefectReportsCount($customer->getDefectReportsCount() + 1);
        }

        $this->entityManager->persist($customer);

        $this->logger->debug('Updated customer account', [
            'customer_id' => $customer->getId(),
            'return_requests_count' => $customer->getReturnRequestsCount(),
        ]);
    }

    private function recordReturnAnalytics(ReturnRequest $returnRequest): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('return_initiated');
        $analyticsEvent->setCustomerId($returnRequest->getCustomerId());
        $analyticsEvent->setPayload([
            'return_id' => $returnRequest->getId(),
            'order_id' => $returnRequest->getOrderId(),
            'reason' => $returnRequest->getReason(),
            'item_count' => count($returnRequest->getItems()),
            'rma_number' => $returnRequest->getRmaNumber(),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded return analytics', [
            'return_id' => $returnRequest->getId(),
            'event' => 'return_initiated',
        ]);
    }

    private function createAuditEntry(ReturnRequest $returnRequest): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('RETURN_INITIATED');
        $auditEntry->setEntityType('return_request');
        $auditEntry->setEntityId($returnRequest->getId());
        $auditEntry->setUserId($returnRequest->getCustomerId());
        $auditEntry->setMetadata([
            'order_id' => $returnRequest->getOrderId(),
            'reason' => $returnRequest->getReason(),
            'item_count' => count($returnRequest->getItems()),
            'rma_number' => $returnRequest->getRmaNumber(),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'return_id' => $returnRequest->getId(),
            'action' => 'RETURN_INITIATED',
        ]);
    }

    private function triggerReturnShipping(ReturnRequest $returnRequest): void
    {
        $shippingLabel = new \App\Entity\ShippingLabel();
        $shippingLabel->setReturnRequest($returnRequest);
        $shippingLabel->setCarrier('USPS');
        $shippingLabel->setService('PriorityMail');
        $shippingLabel->setTrackingNumber($returnRequest->getRmaNumber());
        $shippingLabel->setStatus('pending');
        $shippingLabel->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($shippingLabel);

        $this->queueService->publish('shipping.return_label', [
            'label_id' => $shippingLabel->getId(),
            'return_id' => $returnRequest->getId(),
            'customer_id' => $returnRequest->getCustomerId(),
            'carrier' => 'USPS',
            'tracking_number' => $returnRequest->getRmaNumber(),
        ]);

        $this->logger->info('Triggered return shipping label generation', [
            'return_id' => $returnRequest->getId(),
        ]);
    }
}
