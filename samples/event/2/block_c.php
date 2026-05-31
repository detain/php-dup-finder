<?php
declare(strict_types=1);

namespace App\Domain\Shipping\EventHandler;

use App\Entity\Shipment;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\CarrierService;
use App\Service\NotificationService;
use App\Service\TrackingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class ShipmentDeliveredEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly CarrierService $carrierService,
        private readonly NotificationService $notificationService,
        private readonly TrackingService $trackingService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Shipment $shipment): void
    {
        $this->logger->info('Processing shipment delivered event', [
            'shipment_id' => $shipment->getId(),
            'tracking_number' => $shipment->getTrackingNumber(),
            'order_id' => $shipment->getOrderId(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->updateOrderStatus($shipment);
            $this->recordDeliveryProof($shipment);
            $this->notifyCustomer($shipment);
            $this->updateCarrierRecords($shipment);
            $this->recordDeliveryAnalytics($shipment);
            $this->createAuditEntry($shipment);
            $this->processFulfillmentCompletion($shipment);

            $this->entityManager->commit();

            $this->logger->info('Shipment delivered event processed', [
                'shipment_id' => $shipment->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process shipment delivered event', [
                'shipment_id' => $shipment->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function updateOrderStatus(Shipment $shipment): void
    {
        $order = $this->entityManager
            ->getRepository(\App\Entity\Order::class)
            ->find($shipment->getOrderId());

        if ($order === null) {
            throw new \RuntimeException('Order not found: ' . $shipment->getOrderId());
        }

        $order->setStatus('delivered');
        $order->setDeliveredAt(new \DateTimeImmutable());

        $allShipments = $this->entityManager
            ->getRepository(Shipment::class)
            ->findBy(['orderId' => $order->getId()]);

        $allDelivered = true;
        foreach ($allShipments as $s) {
            if ($s->getId() !== $shipment->getId() && $s->getStatus() !== 'delivered') {
                $allDelivered = false;
                break;
            }
        }

        if ($allDelivered) {
            $order->setFulfillmentStatus('fulfilled');
        }

        $this->entityManager->persist($order);

        $this->logger->debug('Updated order status to delivered', [
            'order_id' => $order->getId(),
            'all_shipments_delivered' => $allDelivered,
        ]);
    }

    private function recordDeliveryProof(Shipment $shipment): void
    {
        $proof = new \App\Entity\DeliveryProof();
        $proof->setShipment($shipment);
        $proof->setSignature($shipment->getRecipientSignature());
        $proof->setPhotoUrls($shipment->getDeliveryPhotos());
        $proof->setCoordinates($shipment->getDeliveryCoordinates());
        $proof->setDeliveredAt($shipment->getDeliveredAt());
        $proof->setReceivedBy($shipment->getReceivedBy());
        $proof->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($proof);

        $this->logger->debug('Recorded delivery proof', [
            'shipment_id' => $shipment->getId(),
            'proof_id' => $proof->getId(),
        ]);
    }

    private function notifyCustomer(Shipment $shipment): void
    {
        $order = $this->entityManager
            ->getRepository(\App\Entity\Order::class)
            ->find($shipment->getOrderId());

        $customer = $order?->getCustomer();

        if ($customer === null) {
            $this->logger->warning('Customer not found for shipment notification', [
                'shipment_id' => $shipment->getId(),
            ]);
            return;
        }

        $template = $this->entityManager
            ->getRepository(\App\Entity\EmailTemplate::class)
            ->findOneBy(['code' => 'shipment_delivered']);

        if ($template !== null) {
            $this->queueService->publish('email.outbound', [
                'template_id' => $template->getId(),
                'recipient' => $customer->getEmail(),
                'variables' => [
                    'first_name' => $customer->getFirstName(),
                    'order_id' => $order->getId(),
                    'tracking_number' => $shipment->getTrackingNumber(),
                    'carrier' => $shipment->getCarrier(),
                    'delivered_at' => $shipment->getDeliveredAt()->format('Y-m-d H:i:s'),
                ],
                'priority' => 'normal',
            ]);
        }

        if ($customer->getPhone()) {
            $this->queueService->publish('sms.outbound', [
                'recipient' => $customer->getPhone(),
                'message' => sprintf(
                    'Your order #%d has been delivered. Tracking: %s',
                    $order->getId(),
                    $shipment->getTrackingNumber()
                ),
            ]);
        }

        $this->logger->debug('Notified customer of delivery', [
            'shipment_id' => $shipment->getId(),
            'customer_id' => $customer->getId(),
        ]);
    }

    private function updateCarrierRecords(Shipment $shipment): void
    {
        try {
            $this->carrierService->updateDeliveryStatus(
                $shipment->getCarrier(),
                $shipment->getTrackingNumber(),
                'delivered',
                [
                    'delivered_at' => $shipment->getDeliveredAt()->format(\DATE_ATOM),
                    'received_by' => $shipment->getReceivedBy(),
                    'signature' => $shipment->getRecipientSignature(),
                ]
            );

            $this->logger->debug('Updated carrier records', [
                'shipment_id' => $shipment->getId(),
                'carrier' => $shipment->getCarrier(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to update carrier records', [
                'shipment_id' => $shipment->getId(),
                'carrier' => $shipment->getCarrier(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function recordDeliveryAnalytics(Shipment $shipment): void
    {
        $order = $this->entityManager
            ->getRepository(\App\Entity\Order::class)
            ->find($shipment->getOrderId());

        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('shipment_delivered');
        $analyticsEvent->setCustomerId($order?->getCustomerId() ?? 0);
        $analyticsEvent->setPayload([
            'shipment_id' => $shipment->getId(),
            'order_id' => $shipment->getOrderId(),
            'tracking_number' => $shipment->getTrackingNumber(),
            'carrier' => $shipment->getCarrier(),
            'delivery_time_hours' => $shipment->getDeliveredAt()->diff(
                $shipment->getShippedAt()
            )->h,
            'destination_zip' => $shipment->getDestinationZip(),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded delivery analytics', [
            'shipment_id' => $shipment->getId(),
            'event' => 'shipment_delivered',
        ]);
    }

    private function createAuditEntry(Shipment $shipment): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('SHIPMENT_DELIVERED');
        $auditEntry->setEntityType('shipment');
        $auditEntry->setEntityId($shipment->getId());
        $auditEntry->setUserId(0);
        $auditEntry->setMetadata([
            'order_id' => $shipment->getOrderId(),
            'tracking_number' => $shipment->getTrackingNumber(),
            'carrier' => $shipment->getCarrier(),
            'delivered_at' => $shipment->getDeliveredAt()->format(\DATE_ATOM),
            'received_by' => $shipment->getReceivedBy(),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'shipment_id' => $shipment->getId(),
            'action' => 'SHIPMENT_DELIVERED',
        ]);
    }

    private function processFulfillmentCompletion(Shipment $shipment): void
    {
        $order = $this->entityManager
            ->getRepository(\App\Entity\Order::class)
            ->find($shipment->getOrderId());

        if ($order === null || $order->getFulfillmentStatus() !== 'fulfilled') {
            return;
        }

        $this->queueService->publish('order.completion', [
            'order_id' => $order->getId(),
            'customer_id' => $order->getCustomerId(),
            'total_amount' => $order->getTotal()->getAmount(),
            'completed_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);

        $reviewRequest = new \App\Entity\ReviewRequest();
        $reviewRequest->setOrder($order);
        $reviewRequest->setCustomer($order->getCustomer());
        $reviewRequest->setStatus('pending');
        $reviewRequest->setScheduledFor(
            (new \DateTimeImmutable())->modify('+3 days')
        );
        $reviewRequest->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($reviewRequest);

        $this->logger->info('Processed fulfillment completion', [
            'order_id' => $order->getId(),
        ]);
    }
}
