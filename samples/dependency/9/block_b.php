<?php

declare(strict_types=1);

namespace App\Application\Queue;

/**
 * Notification queue publisher.
 * The MessageQueueInterface is manually injected here, duplicated from
 * OrderQueuePublisher and other queue publishers.
 */
class NotificationQueuePublisher
{
    private const QUEUE_NAME = 'notifications';
    private const EXCHANGE_NAME = 'notifications.exchange';

    private MessageQueueInterface $queue;
    private SerializerInterface $serializer;

    public function __construct(
        MessageQueueInterface $queue,
        SerializerInterface $serializer
    ) {
        $this->queue = $queue;
        $this->serializer = $serializer;
    }

    public function publishEmailQueued(string $recipient, string $template, array $data): void
    {
        $message = [
            'event' => 'email.queued',
            'recipient' => $recipient,
            'template' => $template,
            'data' => $data,
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

    public function publishSmsQueued(string $phoneNumber, string $message): void
    {
        $message = [
            'event' => 'sms.queued',
            'phone_number' => $phoneNumber,
            'message' => $message,
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

    public function publishPushNotificationQueued(
        string $userId,
        string $title,
        string $body,
        array $data = []
    ): void {

        $message = [
            'event' => 'push_notification.queued',
            'user_id' => $userId,
            'notification' => [
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ],
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

    public function publishWelcomeEmailQueued(string $userId, string $email): void
    {
        $message = [
            'event' => 'welcome_email.queued',
            'user_id' => $userId,
            'email' => $email,
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

    public function publishPasswordResetQueued(string $userId, string $email, string $resetToken): void
    {
        $message = [
            'event' => 'password_reset_email.queued',
            'user_id' => $userId,
            'email' => $email,
            'reset_token' => $resetToken,
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

    public function publishOrderNotificationQueued(string $orderId, string $customerEmail): void
    {
        $message = [
            'event' => 'order_notification.queued',
            'order_id' => $orderId,
            'customer_email' => $customerEmail,
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
