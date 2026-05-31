<?php
declare(strict_types=1);

namespace Acme\Queue;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;

final class QueueConfig
{
    public const HOST        = 'rabbit.internal';
    public const PORT        = 5672;
    public const USER        = 'svc-orders';
    public const PASS        = 'svc-orders-secret';
    public const VHOST       = 'acme-prod';
    public const PREFETCH    = 25;
    public const TTL_MS      = 60_000;
    public const MAX_ATTEMPT = 5;
    public const DLX         = 'acme.dlx';

    public static function openChannel(string $queueName): AMQPChannel
    {
        $conn = new AMQPStreamConnection(
            self::HOST, self::PORT, self::USER, self::PASS, self::VHOST
        );
        $ch = $conn->channel();
        $ch->basic_qos(0, self::PREFETCH, false);
        $ch->queue_declare($queueName, false, true, false, false, false, [
            'x-dead-letter-exchange'  => ['S', self::DLX],
            'x-message-ttl'           => ['I', self::TTL_MS],
            'x-max-delivery-attempts' => ['I', self::MAX_ATTEMPT],
        ]);

        return $ch;
    }
}

// Usage: $channel = QueueConfig::openChannel('orders.process');
