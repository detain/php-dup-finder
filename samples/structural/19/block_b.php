<?php
declare(strict_types=1);

namespace Audit\Logging;

use Psr\Log\LoggerInterface;

final class OrderAuditLogger
{
    private const BUFFER_SIZE = 100;
    private const FLUSH_INTERVAL_SECONDS = 60;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly AuditEventSerializer $serializer,
        private readonly AuditEventRepository $repository,
    ) {}

    public function log(AuditEntry $entry): void
    {
        $context = $this->prepareContext($entry);
        $this->logger->info('Audit event', $context);

        $serializedEntry = $this->serializer->serialize($entry);
        $this->bufferEvent($serializedEntry);
    }

    public function logBatch(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->log($entry);
        }
    }

    public function logOrderCreated(Order $order, OrderContext $context): void
    {
        $this->log(new AuditEntry(
            eventType: 'order.created',
            userId: $context->getUserId(),
            actorId: $context->getActorId(),
            timestamp: new \DateTimeImmutable(),
            entityType: 'order',
            entityId: $order->getId(),
            metadata: [
                'order_number' => $order->getOrderNumber(),
                'total_amount' => $order->getTotal(),
                'currency' => $order->getCurrency(),
                'customer_email' => $order->getCustomerEmail(),
                'ip_address' => $context->getIpAddress(),
            ],
        ));
    }

    public function logOrderUpdated(Order $order, array $changes, OrderContext $context): void
    {
        $this->log(new AuditEntry(
            eventType: 'order.updated',
            userId: $context->getUserId(),
            actorId: $context->getActorId(),
            timestamp: new \DateTimeImmutable(),
            entityType: 'order',
            entityId: $order->getId(),
            metadata: [
                'changes' => $changes,
                'order_number' => $order->getOrderNumber(),
                'ip_address' => $context->getIpAddress(),
            ],
        ));
    }

    public function logOrderCancelled(Order $order, string $reason, OrderContext $context): void
    {
        $this->log(new AuditEntry(
            eventType: 'order.cancelled',
            userId: $context->getUserId(),
            actorId: $context->getActorId(),
            timestamp: new \DateTimeImmutable(),
            entityType: 'order',
            entityId: $order->getId(),
            metadata: [
                'order_number' => $order->getOrderNumber(),
                'reason' => $reason,
                'refund_amount' => $order->getTotal(),
                'ip_address' => $context->getIpAddress(),
            ],
        ));
    }

    public function logOrderPaid(Order $order, PaymentDetails $payment, OrderContext $context): void
    {
        $this->log(new AuditEntry(
            eventType: 'order.paid',
            userId: $context->getUserId(),
            actorId: $context->getActorId(),
            timestamp: new \DateTimeImmutable(),
            entityType: 'order',
            entityId: $order->getId(),
            metadata: [
                'order_number' => $order->getOrderNumber(),
                'payment_method' => $payment->getMethod(),
                'transaction_id' => $payment->getTransactionId(),
                'amount_paid' => $payment->getAmount(),
            ],
        ));
    }

    public function logOrderShipped(Order $order, ShippingDetails $shipping, OrderContext $context): void
    {
        $this->log(new AuditEntry(
            eventType: 'order.shipped',
            userId: $context->getUserId(),
            actorId: $context->getActorId(),
            timestamp: new \DateTimeImmutable(),
            entityType: 'order',
            entityId: $order->getId(),
            metadata: [
                'order_number' => $order->getOrderNumber(),
                'tracking_number' => $shipping->getTrackingNumber(),
                'carrier' => $shipping->getCarrier(),
                'estimated_delivery' => $shipping->getEstimatedDelivery()->format(\DateTimeInterface::ISO8601),
            ],
        ));
    }

    private function prepareContext(AuditEntry $entry): array
    {
        return [
            'event_type' => $entry->eventType,
            'entity_type' => $entry->entityType,
            'entity_id' => $entry->entityId,
            'actor_id' => $entry->actorId,
            'timestamp' => $entry->timestamp->format(\DateTimeInterface::ISO8601),
        ];
    }

    private function bufferEvent(string $serializedEntry): void
    {
        static $buffer = [];
        static $lastFlush = 0;

        $buffer[] = $serializedEntry;

        if (count($buffer) >= self::BUFFER_SIZE || (time() - $lastFlush) >= self::FLUSH_INTERVAL_SECONDS) {
            $this->flushBuffer($buffer);
            $buffer = [];
            $lastFlush = time();
        }
    }

    private function flushBuffer(array $buffer): void
    {
        foreach ($buffer as $serializedEntry) {
            try {
                $entry = $this->serializer->deserialize($serializedEntry);
                $this->repository->persist($entry);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to persist audit entry', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
