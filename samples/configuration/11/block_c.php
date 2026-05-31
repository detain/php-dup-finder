<?php

declare(strict_types=1);

namespace App\Services\Queue;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use Psr\Log\LoggerInterface;

final class MessageQueueService
{
    private const CONNECTION_TIMEOUT = 3.0;
    private const FRAME_SIZE = 131072;
    private const HEARTBEAT = 60;
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY = 150;
    private const PREFETCH_COUNT = 10;
    private const POOL_SIZE = 8;

    private ?AMQPStreamConnection $connection = null;
    private ?AMQPChannel $channel = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $host = 'localhost',
        private readonly int $port = 5672,
        private readonly string $user = 'guest',
        private readonly string $password = 'guest',
        private readonly string $vhost = '/'
    ) {}

    public function publish(string $exchange, string $routingKey, array $payload): bool
    {
        $this->ensureConnection();

        $message = new AMQPMessage(
            json_encode($payload, JSON_THROW_ON_ERROR),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'timestamp' => time(),
                'message_id' => $this->generateMessageId(),
            ]
        );

        $attempts = 0;

        while ($attempts < self::MAX_RETRIES) {
            try {
                $this->channel->basic_publish(
                    $message,
                    $exchange,
                    $routingKey
                );

                $this->logger->info('Message published to queue', [
                    'exchange' => $exchange,
                    'routing_key' => $routingKey,
                    'message_id' => $message->get('message_id'),
                    'attempt' => $attempts + 1,
                ]);

                return true;
            } catch (AMQPExceptionInterface $e) {
                $attempts++;
                $this->logger->error('Failed to publish message', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempts,
                    'max_retries' => self::MAX_RETRIES,
                    'exchange' => $exchange,
                    'routing_key' => $routingKey,
                ]);

                if ($attempts >= self::MAX_RETRIES) {
                    return false;
                }

                usleep(self::RETRY_DELAY * 1000 * $attempts);
                $this->reconnect();
            }
        }

        return false;
    }

    public function consume(string $queue, callable $callback): void
    {
        $this->ensureConnection();

        $this->channel->basic_qos(
            0,
            self::PREFETCH_COUNT,
            false
        );

        $this->channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($callback) {
                try {
                    $payload = json_decode(
                        $message->getBody(),
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );

                    $result = $callback($payload, $message);

                    if ($result === true) {
                        $message->ack();
                        $this->logger->debug('Message processed successfully', [
                            'message_id' => $message->get('message_id'),
                            'queue' => $message->getRoutingKey(),
                        ]);
                    } else {
                        $message->nack(true);
                        $this->logger->warning('Message processing failed, requeued', [
                            'message_id' => $message->get('message_id'),
                        ]);
                    }
                } catch (\Throwable $e) {
                    $message->nack(true);
                    $this->logger->error('Exception while processing message', [
                        'error' => $e->getMessage(),
                        'message_id' => $message->get('message_id'),
                    ]);
                }
            }
        );

        $this->logger->info('Started consuming from queue', [
            'queue' => $queue,
            'prefetch_count' => self::PREFETCH_COUNT,
            'pool_size' => self::POOL_SIZE,
        ]);

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    public function declareExchange(string $name, string $type = 'direct'): void
    {
        $this->ensureConnection();

        $this->channel->exchange_declare(
            $name,
            $type,
            false,
            true,
            false
        );

        $this->logger->info('Exchange declared', [
            'name' => $name,
            'type' => $type,
        ]);
    }

    public function declareQueue(string $name, string $exchange, string $routingKey): void
    {
        $this->ensureConnection();

        $this->channel->queue_declare(
            $name,
            false,
            true,
            false,
            false
        );

        $this->channel->queue_bind(
            $name,
            $exchange,
            $routingKey
        );

        $this->logger->info('Queue declared and bound', [
            'queue' => $name,
            'exchange' => $exchange,
            'routing_key' => $routingKey,
        ]);
    }

    private function ensureConnection(): void
    {
        if ($this->connection !== null && $this->connection->isConnected()) {
            return;
        }

        $this->connect();
    }

    private function connect(): void
    {
        try {
            $this->connection = new AMQPStreamConnection(
                $this->host,
                $this->port,
                $this->user,
                $this->password,
                $this->vhost,
                false,
                'AMQPLAIN',
                null,
                'en_US',
                self::CONNECTION_TIMEOUT,
                self::FRAME_SIZE,
                null,
                true,
                self::HEARTBEAT
            );

            $this->channel = $this->connection->channel();

            $this->logger->info('AMQP connection established', [
                'host' => $this->host,
                'port' => $this->port,
                'vhost' => $this->vhost,
                'pool_size' => self::POOL_SIZE,
                'heartbeat' => self::HEARTBEAT,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to connect to AMQP broker', [
                'error' => $e->getMessage(),
                'host' => $this->host,
                'port' => $this->port,
            ]);
            throw $e;
        }
    }

    private function reconnect(): void
    {
        $this->channel = null;
        $this->connection = null;
        $this->connect();
    }

    private function generateMessageId(): string
    {
        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(6))
        );
    }

    public function __destruct()
    {
        if ($this->channel !== null) {
            try {
                $this->channel->close();
            } catch (\Exception $e) {
            }
        }

        if ($this->connection !== null) {
            try {
                $this->connection->close();
            } catch (\Exception $e) {
            }
        }
    }
}
