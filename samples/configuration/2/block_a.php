<?php
declare(strict_types=1);

namespace Acme\Workers\Orders;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

final class OrderConsumer
{
    private AMQPStreamConnection $conn;
    private AMQPChannel $channel;

    public function __construct(private LoggerInterface $log)
    {
        $this->conn = new AMQPStreamConnection(
            'rabbit.internal',
            5672,
            'svc-orders',
            'svc-orders-secret',
            'acme-prod'
        );
        $this->channel = $this->conn->channel();
        $this->channel->basic_qos(0, 25, false);
        $this->channel->queue_declare('orders.process', false, true, false, false, false, [
            'x-dead-letter-exchange'    => ['S', 'acme.dlx'],
            'x-message-ttl'             => ['I', 60_000],
            'x-max-delivery-attempts'   => ['I', 5],
        ]);
    }

    public function run(): void
    {
        $this->channel->basic_consume('orders.process', '', false, false, false, false,
            function (AMQPMessage $msg): void {
                try {
                    $payload = json_decode($msg->getBody(), true, 512, JSON_THROW_ON_ERROR);
                    $this->log->info('orders.process', ['id' => $payload['id'] ?? null]);
                    $msg->ack();
                } catch (\Throwable $e) {
                    $this->log->error('orders.fail', ['error' => $e->getMessage()]);
                    $msg->nack(false, false);
                }
            }
        );

        while ($this->channel->is_open()) {
            $this->channel->wait();
        }
    }
}
