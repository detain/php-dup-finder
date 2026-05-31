<?php

declare(strict_types=1);

namespace App\Services\Queue;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;
use App\Services\OrderService;
use App\Services\NotificationService;
use App\Services\LoggingService;
use Psr\Log\LoggerInterface;

class RabbitMQConsumerHandler
{
    private AMQPStreamConnection $connection;
    private AMQPChannel $channel;
    private OrderService $orderService;
    private NotificationService $notificationService;
    private LoggingService $loggingService;
    private LoggerInterface $logger;
    private array $consumers = [];
    private bool $running = false;

    public function __construct(
        string $host,
        int $port,
        string $user,
        string $password,
        OrderService $orderService,
        NotificationService $notificationService,
        LoggingService $loggingService,
        LoggerInterface $logger
    ) {
        $this->connection = new AMQPStreamConnection($host, $port, $user, $password);
        $this->channel = $this->connection->channel();
        $this->orderService = $orderService;
        $this->notificationService = $notificationService;
        $this->loggingService = $loggingService;
        $this->logger = $logger;

        $this->setupQueues();
        $this->registerConsumers();
    }

    private function setupQueues(): void
    {
        // Order processing queue
        $this->channel->queue_declare(
            'orders.create',
            passive: false,
            durable: true,
            exclusive: false,
            auto_delete: false
        );

        // Notification queue
        $this->channel->queue_declare(
            'notifications.send',
            passive: false,
            durable: true,
            exclusive: false,
            auto_delete: false
        );

        // Email queue
        $this->channel->queue_declare(
            'emails.send',
            passive: false,
            durable: true,
            exclusive: false,
            auto_delete: false
        );

        // Dead letter queue for failed messages
        $this->channel->queue_declare(
            'orders.create.dlq',
            passive: false,
            durable: true,
            exclusive: false,
            auto_delete: false
        );
    }

    private function registerConsumers(): void
    {
        $this->consumers['orders.create'] = function (AMQPMessage $msg) {
            $this->handleOrderCreation($msg);
        };

        $this->consumers['notifications.send'] = function (AMQPMessage $msg) {
            $this->handleNotification($msg);
        };

        $this->consumers['emails.send'] = function (AMQPMessage $msg) {
            $this->handleEmailSend($msg);
        };
    }

    public function startConsuming(): void
    {
        $this->running = true;

        foreach ($this->consumers as $queue => $callback) {
            $this->channel->basic_qos(null, 1, null);
            $this->channel->basic_consume(
                queue: $queue,
                consumer_tag: '',
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: $callback
            );
        }

        $this->logger->info('RabbitMQ consumer started');

        while ($this->running && $this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    public function stopConsuming(): void
    {
        $this->running = false;
        $this->channel->basic_cancel('');

        $this->logger->info('RabbitMQ consumer stopped');
    }

    private function handleOrderCreation(AMQPMessage $msg): void
    {
        $body = json_decode($msg->getBody(), true);

        $this->logger->info('Processing order creation', ['order_id' => $body['order_id'] ?? null]);

        try {
            $order = $this->orderService->createOrder($body);

            $this->channel->basic_ack($msg->getDeliveryTag());

            $this->notifyOrderCreated($order);

            $this->loggingService->log('order_created', [
                'order_id' => $order['id'],
                'customer_email' => $order['customer_email'],
                'total' => $order['total'],
            ]);
        } catch (\Exception $e) {
            $this->handleFailure($msg, $e, 'orders.create.dlq');
        }
    }

    private function handleNotification(AMQPMessage $msg): void
    {
        $body = json_decode($msg->getBody(), true);

        $this->logger->info('Processing notification', [
            'user_id' => $body['user_id'] ?? null,
            'type' => $body['type'] ?? null,
        ]);

        try {
            $this->notificationService->send(
                $body['user_id'],
                $body['type'],
                $body['data'] ?? []
            );

            $this->channel->basic_ack($msg->getDeliveryTag());
        } catch (\Exception $e) {
            $this->handleFailure($msg, $e);
        }
    }

    private function handleEmailSend(AMQPMessage $msg): void
    {
        $body = json_decode($msg->getBody(), true);

        $this->logger->info('Processing email', [
            'to' => $body['to'] ?? null,
            'template' => $body['template'] ?? null,
        ]);

        try {
            $this->notificationService->sendEmail(
                $body['to'],
                $body['template'],
                $body['data'] ?? []
            );

            $this->channel->basic_ack($msg->getDeliveryTag());
        } catch (\Exception $e) {
            $this->handleFailure($msg, $e);
        }
    }

    private function handleFailure(AMQPMessage $msg, \Exception $e, ?string $deadLetterQueue = null): void
    {
        $this->logger->error('Message processing failed', [
            'error' => $e->getMessage(),
            'body' => $msg->getBody(),
        ]);

        // Reject and requeue or send to DLQ
        if ($deadLetterQueue) {
            $this->channel->basic_publish(
                $msg,
                exchange: '',
                routing_key: $deadLetterQueue
            );
        }

        $this->channel->basic_nack($msg->getDeliveryTag(), false, false);
    }

    private function notifyOrderCreated(array $order): void
    {
        $this->channel->basic_publish(
            new AMQPMessage(json_encode([
                'user_id' => $order['customer_id'],
                'type' => 'order_created',
                'data' => $order,
            ])),
            exchange: '',
            routing_key: 'notifications.send'
        );
    }

    public function __destruct()
    {
        $this->channel->close();
        $this->connection->close();
    }
}
