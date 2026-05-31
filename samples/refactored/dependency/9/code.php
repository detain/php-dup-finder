<?php

declare(strict_types=1);

namespace App\Application\Queue;

use App\Infrastructure\MessageQueue\MessageQueueInterface;

/**
 * Base queue publisher with shared queue configuration.
 * Centralizes MessageQueueInterface injection.
 */
abstract class BaseQueuePublisher
{
    protected const QUEUE_NAME = '';
    protected const EXCHANGE_NAME = '';

    protected MessageQueueInterface $queue;
    protected SerializerInterface $serializer;

    public function __construct(
        MessageQueueInterface $queue,
        SerializerInterface $serializer
    ) {
        $this->queue = $queue;
        $this->serializer = $serializer;
    }

    protected function publishMessage(string $event, array $data, int $priority = 5): void
    {
        $message = [
            'event' => $event,
            'data' => $data,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ];

        $this->queue->publish(
            static::QUEUE_NAME,
            $this->serializer->serialize($message),
            [
                'content_type' => 'application/json',
                'delivery_mode' => 2,
                'priority' => $priority,
            ]
        );
    }
}

class OrderQueuePublisher extends BaseQueuePublisher
{
    protected const QUEUE_NAME = 'orders';

    public function publishOrderCreated(string $orderId, array $orderData): void
    {
        $this->publishMessage('order.created', [
            'order_id' => $orderId,
            ...$orderData,
        ], 5);
    }
}
