<?php

declare(strict_types=1);

namespace App\Services\Queue;

use Psr\Log\LoggerInterface;

trait QueueConsumerTrait
{
    protected LoggerInterface $logger;
    protected OrderService $orderService;
    protected NotificationService $notificationService;
    protected LoggingService $loggingService;
    protected bool $running = false;

    protected function processQueueMessage(string $queueName, array $body): void
    {
        $this->logger->info("Processing message from {$queueName}", [
            'id' => $body['id'] ?? null,
        ]);

        match ($queueName) {
            'orders.create' => $this->handleOrderCreation($body),
            'notifications.send' => $this->handleNotification($body),
            'emails.send' => $this->handleEmailSend($body),
            default => throw new \InvalidArgumentException("Unknown queue: {$queueName}"),
        };
    }

    protected function handleOrderCreation(array $body): void
    {
        $order = $this->orderService->createOrder($body);

        $this->loggingService->log('order_created', [
            'order_id' => $order['id'],
            'customer_email' => $order['customer_email'],
        ]);

        $this->publishToQueue('notifications.send', [
            'user_id' => $order['customer_id'],
            'type' => 'order_created',
            'data' => $order,
        ]);
    }

    protected function handleNotification(array $body): void
    {
        $this->notificationService->send(
            $body['user_id'],
            $body['type'],
            $body['data'] ?? []
        );
    }

    protected function handleEmailSend(array $body): void
    {
        $this->notificationService->sendEmail(
            $body['to'],
            $body['template'],
            $body['data'] ?? []
        );
    }

    protected function handleProcessingFailure(
        string $queueName,
        array $body,
        \Exception $e
    ): void {
        $this->logger->error('Message processing failed', [
            'queue' => $queueName,
            'error' => $e->getMessage(),
        ]);

        // Publish to DLQ if this isn't already a DLQ message
        if (!str_ends_with($queueName, '.dlq')) {
            $this->publishToQueue("{$queueName}.dlq", [
                'original_queue' => $queueName,
                'body' => $body,
                'error' => $e->getMessage(),
                'failed_at' => date('c'),
            ]);
        }
    }

    abstract protected function publishToQueue(string $queueName, array $message): void;
}
