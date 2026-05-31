<?php

declare(strict_types=1);

namespace App\Application\Queue;

/**
 * Analytics queue publisher.
 * The MessageQueueInterface is manually injected here, duplicated from
 * OrderQueuePublisher, NotificationQueuePublisher, and other queue publishers.
 */
class AnalyticsQueuePublisher
{
    private const QUEUE_NAME = 'analytics';
    private const EXCHANGE_NAME = 'analytics.exchange';

    private MessageQueueInterface $queue;
    private SerializerInterface $serializer;

    public function __construct(
        MessageQueueInterface $queue,
        SerializerInterface $serializer
    ) {
        $this->queue = $queue;
        $this->serializer = $serializer;
    }

    public function publishPageView(string $sessionId, string $pageUrl, ?string $referrer): void
    {
        $message = [
            'event' => 'page_view',
            'session_id' => $sessionId,
            'page_url' => $pageUrl,
            'referrer' => $referrer,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ];

        $this->queue->publish(
            self::QUEUE_NAME,
            $this->serializer->serialize($message),
            [
                'content_type' => 'application/json',
                'delivery_mode' => 2,
                'priority' => 3,
            ]
        );
    }

    public function publishProductView(
        string $sessionId,
        string $productId,
        string $productName,
        float $price
    ): void {

        $message = [
            'event' => 'product_view',
            'session_id' => $sessionId,
            'product_id' => $productId,
            'product_name' => $productName,
            'price' => $price,
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

    public function publishAddToCart(
        string $sessionId,
        string $productId,
        int $quantity,
        float $unitPrice
    ): void {

        $message = [
            'event' => 'add_to_cart',
            'session_id' => $sessionId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
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

    public function publishCheckoutStarted(string $sessionId, string $orderId, float $total): void
    {
        $message = [
            'event' => 'checkout_started',
            'session_id' => $sessionId,
            'order_id' => $orderId,
            'total' => $total,
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

    public function publishPurchaseCompleted(
        string $orderId,
        string $customerId,
        float $orderTotal,
        array $items
    ): void {

        $message = [
            'event' => 'purchase_completed',
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'order_total' => $orderTotal,
            'items' => $items,
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

    public function publishSearchQuery(string $sessionId, string $query, int $resultsCount): void
    {
        $message = [
            'event' => 'search_query',
            'session_id' => $sessionId,
            'query' => $query,
            'results_count' => $resultsCount,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ];

        $this->queue->publish(
            self::QUEUE_NAME,
            $this->serializer->serialize($message),
            [
                'content_type' => 'application/json',
                'delivery_mode' => 2,
                'priority' => 3,
            ]
        );
    }
}
