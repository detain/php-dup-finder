<?php
declare(strict_types=1);

namespace App\Domain\Order\EventHandler;

use App\Entity\Order;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\NotificationService;
use App\Service\AnalyticsService;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class OrderPlacedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly NotificationService $notificationService,
        private readonly AnalyticsService $analyticsService,
        private readonly AuditService $auditService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Order $order): void
    {
        $this->logger->info('Processing order placed event', [
            'order_id' => $order->getId(),
            'customer_id' => $order->getCustomerId(),
            'total' => $order->getTotal()->getAmount(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->updateInventoryReservation($order);
            $this->publishToProcessingQueue($order);
            $this->recordAnalyticsEvent($order);
            $this->createAuditLogEntry($order);
            $this->triggerExternalWebhooks($order);

            $this->entityManager->commit();

            $this->logger->info('Order placed event processed successfully', [
                'order_id' => $order->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process order placed event', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function updateInventoryReservation(Order $order): void
    {
        foreach ($order->getItems() as $item) {
            $product = $this->entityManager
                ->getRepository(\App\Entity\Product::class)
                ->find($item->getProductId());

            if ($product === null) {
                throw new \RuntimeException(
                    sprintf('Product %d not found for order item', $item->getProductId())
                );
            }

            $reservedQty = $product->getReservedQuantity() + $item->getQuantity();
            $product->setReservedQuantity($reservedQty);

            $this->entityManager->persist($product);

            $this->logger->debug('Reserved inventory', [
                'product_id' => $product->getId(),
                'quantity' => $item->getQuantity(),
                'total_reserved' => $reservedQty,
            ]);
        }
    }

    private function publishToProcessingQueue(Order $order): void
    {
        $payload = [
            'event_type' => 'order.placed',
            'order_id' => $order->getId(),
            'customer_id' => $order->getCustomerId(),
            'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'items' => array_map(
                fn($item) => [
                    'product_id' => $item->getProductId(),
                    'quantity' => $item->getQuantity(),
                    'unit_price' => $item->getUnitPrice()->getAmount(),
                ],
                $order->getItems()
            ),
        ];

        $this->queueService->publish('order.processing', $payload);

        $this->logger->debug('Published order to processing queue', [
            'order_id' => $order->getId(),
            'queue' => 'order.processing',
        ]);
    }

    private function recordAnalyticsEvent(Order $order): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('order_placed');
        $analyticsEvent->setCustomerId($order->getCustomerId());
        $analyticsEvent->setPayload([
            'order_id' => $order->getId(),
            'total_amount' => $order->getTotal()->getAmount(),
            'currency' => $order->getTotal()->getCurrency(),
            'item_count' => count($order->getItems()),
            'payment_method' => $order->getPaymentMethod(),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);
        $this->analyticsService->enqueueBatchFlush();

        $this->logger->debug('Recorded analytics event', [
            'order_id' => $order->getId(),
            'event' => 'order_placed',
        ]);
    }

    private function createAuditLogEntry(Order $order): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('ORDER_PLACED');
        $auditEntry->setEntityType('order');
        $auditEntry->setEntityId($order->getId());
        $auditEntry->setUserId($order->getCustomerId());
        $auditEntry->setMetadata([
            'ip_address' => $order->getIpAddress(),
            'user_agent' => $order->getUserAgent(),
            'shipping_method' => $order->getShippingMethod(),
            'coupon_code' => $order->getCouponCode(),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'order_id' => $order->getId(),
            'action' => 'ORDER_PLACED',
        ]);
    }

    private function triggerExternalWebhooks(Order $order): void
    {
        $webhookUrls = $this->entityManager
            ->getRepository(\App\Entity\WebhookEndpoint::class)
            ->findActiveByEventType('order.placed');

        foreach ($webhookUrls as $endpoint) {
            try {
                $this->queueService->publish('webhook.delivery', [
                    'endpoint_id' => $endpoint->getId(),
                    'url' => $endpoint->getUrl(),
                    'secret' => $endpoint->getSecret(),
                    'payload' => [
                        'event' => 'order.placed',
                        'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                        'data' => [
                            'order_id' => $order->getId(),
                            'total' => $order->getTotal()->getAmount(),
                        ],
                    ],
                    'retry_count' => 0,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to queue webhook', [
                    'endpoint_id' => $endpoint->getId(),
                    'order_id' => $order->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
