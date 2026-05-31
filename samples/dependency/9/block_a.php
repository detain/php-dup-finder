<?php

declare(strict_types=1);

namespace App\Application\Queue;

/**
 * Order processing queue publisher.
 * The MessageQueueInterface is manually injected here, duplicated across
 * all services that publish queue messages.
 */
class OrderQueuePublisher
{
    private const QUEUE_NAME = 'orders';
    private const EXCHANGE_NAME = 'orders.exchange';
    private const ROUTING_KEY = 'order.process';

    private MessageQueueInterface $queue;
    private SerializerInterface $serializer;

    public function __construct(
        MessageQueueInterface $queue,
        SerializerInterface $serializer
    ) {
        $this->queue = $queue;
        $this->serializer = $serializer;
    }

    public function publishOrderCreated(string $orderId, array $orderData): void
    {
        $message = [
            'event' => 'order.created',
            'order_id' => $orderId,
            'data' => $orderData,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ];

        $this->queue->publish(
            self::QUEUE_NAME,
            $this->serializer->serialize($message),
            [
                'content_type' => 'application/json',
                'delivery_mode' => 2,
                'priority' => 5,
            ]
        );
    }

    public function publishOrderPaid(string $orderId, array $paymentData): void
    {
        $message = [
            'event' => 'order.paid',
            'order_id' => $orderId,
            'data' => $paymentData,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ];

        $this->queue->publish(
            self::QUEUE_NAME,
            $this->serializer->serialize($message),
            [
                'content_type' => 'application/json',
                'delivery_mode' => 2,
                'priority' => 8,
            ]
        );
    }

    public function publishOrderShipped(string $orderId, string $trackingNumber): void
    {
        $message = [
            'event' => 'order.shipped',
            'order_id' => $orderId,
            'data' => ['tracking_number' => $trackingNumber],
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ];

        $this->queue->publish(
            self::QUEUE_NAME,
            $this->serializer->serialize($message),
            [
                'content_type' => 'application/json',
                'delivery_mode' => 2,
                'priority' => 6,
            ]
        );
    }

    public function publishOrderDelivered(string $orderId): void
    {
        $message = [
            'event' => 'order.delivered',
            'order_id' => $orderId,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ];

        $this->queue->publish(
            self::QUEUE_NAME,
            $this->serializer->serialize($message),
            [
                'content_type' => 'application/json',
                'delivery_mode' => 2,
                'priority' => 4,
            ]
        );
    }

    public function publishOrderCancelled(string $orderId, string $reason): void
    {
        $message = [
            'event' => 'order.cancelled',
            'order_id' => $orderId,
            'data' => ['reason' => $reason],
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ];

        $this->queue->publish(
            self::QUEUE_NAME,
            $this->serializer->serialize($message),
            [
                'content_type' => 'application/json',
                'delivery_mode' => 2,
                'priority' => 7,
            ]
        );
    }

    public function publishOrderRefundRequested(string $orderId, array $refundData): void
    {
        $message = [
            'event' => 'order.refund_requested',
            'order_id' => $orderId,
            'data' => $refundData,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ];

        $this->queue->publish(
            self::QUEUE_NAME,
            $this->serializer->serialize($message),
            [
                'content_type' => 'application/json',
                'delivery_mode' => 2,
                'priority' => 6,
            ]
        );
    }
}
