<?php

declare(strict_types=1);

namespace App\Services\Queue;

use Aws\Sqs\SqsClient;
use Aws\Exception\AwsException;
use App\Services\OrderService;
use App\Services\NotificationService;
use App\Services\LoggingService;
use Psr\Log\LoggerInterface;

class SQSConsumerHandler
{
    private SqsClient $client;
    private OrderService $orderService;
    private NotificationService $notificationService;
    private LoggingService $loggingService;
    private LoggerInterface $logger;
    private array $queues = [];
    private bool $running = false;

    public function __construct(
        array $sqsConfig,
        OrderService $orderService,
        NotificationService $notificationService,
        LoggingService $loggingService,
        LoggerInterface $logger
    ) {
        $this->client = new SqsClient($sqsConfig);
        $this->orderService = $orderService;
        $this->notificationService = $notificationService;
        $this->loggingService = $loggingService;
        $this->logger = $logger;

        $this->setupQueues();
    }

    private function setupQueues(): void
    {
        $queueNames = [
            'orders.create',
            'notifications.send',
            'emails.send',
            'orders.create.dlq',
        ];

        foreach ($queueNames as $name) {
            $result = $this->client->createQueue([
                'QueueName' => $name,
                'Attributes' => [
                    'VisibilityTimeout' => '30',
                    'ReceiveMessageWaitTimeSeconds' => '20',
                ],
            ]);

            $this->queues[$name] = $result['QueueUrl'];
        }
    }

    public function startConsuming(): void
    {
        $this->running = true;

        while ($this->running) {
            foreach ($this->queues as $name => $url) {
                if (!$this->running) {
                    break;
                }

                $this->pollQueue($name, $url);
            }
        }
    }

    public function stopConsuming(): void
    {
        $this->running = false;
        $this->logger->info('SQS consumer stopped');
    }

    private function pollQueue(string $queueName, string $queueUrl): void
    {
        try {
            $result = $this->client->receiveMessage([
                'QueueUrl' => $queueUrl,
                'MaxNumberOfMessages' => 1,
                'WaitTimeSeconds' => 20,
            ]);

            $messages = $result->get('Messages') ?? [];

            foreach ($messages as $message) {
                $this->processMessage($queueName, $message, $queueUrl);
            }
        } catch (AwsException $e) {
            $this->logger->error('SQS poll failed', [
                'queue' => $queueName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function processMessage(string $queueName, array $message, string $queueUrl): void
    {
        $body = json_decode($message['Body'], true);
        $receiptHandle = $message['ReceiptHandle'];

        $this->logger->info('Processing SQS message', [
            'queue' => $queueName,
            'message_id' => $message['MessageId'] ?? null,
        ]);

        try {
            match ($queueName) {
                'orders.create' => $this->handleOrderCreation($body),
                'notifications.send' => $this->handleNotification($body),
                'emails.send' => $this->handleEmailSend($body),
                default => throw new \InvalidArgumentException("Unknown queue: {$queueName}"),
            };

            // Delete message after successful processing
            $this->client->deleteMessage([
                'QueueUrl' => $queueUrl,
                'ReceiptHandle' => $receiptHandle,
            ]);
        } catch (\Exception $e) {
            $this->handleFailure($queueName, $body, $e, $queueUrl, $receiptHandle);
        }
    }

    private function handleOrderCreation(array $body): void
    {
        $this->logger->info('Processing order creation', ['order_id' => $body['order_id'] ?? null]);

        $order = $this->orderService->createOrder($body);

        $this->notifyOrderCreated($order);

        $this->loggingService->log('order_created', [
            'order_id' => $order['id'],
            'customer_email' => $order['customer_email'],
        ]);
    }

    private function handleNotification(array $body): void
    {
        $this->logger->info('Processing notification', [
            'user_id' => $body['user_id'] ?? null,
            'type' => $body['type'] ?? null,
        ]);

        $this->notificationService->send(
            $body['user_id'],
            $body['type'],
            $body['data'] ?? []
        );
    }

    private function handleEmailSend(array $body): void
    {
        $this->logger->info('Processing email', [
            'to' => $body['to'] ?? null,
            'template' => $body['template'] ?? null,
        ]);

        $this->notificationService->sendEmail(
            $body['to'],
            $body['template'],
            $body['data'] ?? []
        );
    }

    private function handleFailure(
        string $queueName,
        array $body,
        \Exception $e,
        string $queueUrl,
        string $receiptHandle
    ): void {
        $this->logger->error('Message processing failed', [
            'queue' => $queueName,
            'error' => $e->getMessage(),
        ]);

        // Send to DLQ if available
        if ($queueName !== 'orders.create.dlq') {
            $dlqUrl = $this->queues['orders.create.dlq'] ?? null;

            if ($dlqUrl) {
                $this->client->sendMessage([
                    'QueueUrl' => $dlqUrl,
                    'MessageBody' => json_encode([
                        'original_queue' => $queueName,
                        'body' => $body,
                        'error' => $e->getMessage(),
                        'failed_at' => date('c'),
                    ]),
                ]);
            }
        }

        // Remove from original queue
        $this->client->deleteMessage([
            'QueueUrl' => $queueUrl,
            'ReceiptHandle' => $receiptHandle,
        ]);
    }

    private function notifyOrderCreated(array $order): void
    {
        $queueUrl = $this->queues['notifications.send'] ?? null;

        if ($queueUrl) {
            $this->client->sendMessage([
                'QueueUrl' => $queueUrl,
                'MessageBody' => json_encode([
                    'user_id' => $order['customer_id'],
                    'type' => 'order_created',
                    'data' => $order,
                ]),
            ]);
        }
    }
}
