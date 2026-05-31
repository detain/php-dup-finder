<?php
declare(strict_types=1);

namespace ECommerce\Events\Orders;

use ECommerce\Outbox\OutboxRepository;
use ECommerce\Messaging\Publisher;
use Psr\Log\LoggerInterface;

final class OrderPlacedEmitter
{
    public function __construct(
        private OutboxRepository $outbox,
        private Publisher $publisher,
        private LoggerInterface $log,
    ) {}

    /**
     * @param array<string,mixed> $payload
     */
    public function emit(string $orderId, array $payload): string
    {
        $eventId = bin2hex(random_bytes(16));
        $this->outbox->insert([
            'event_id'    => $eventId,
            'aggregate'   => 'order',
            'aggregate_id'=> $orderId,
            'type'        => 'order.placed',
            'payload'     => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at'  => date(DATE_ATOM),
            'published_at'=> null,
        ]);

        try {
            $this->publisher->publish('order.placed', [
                'event_id' => $eventId,
                'order_id' => $orderId,
                'data'     => $payload,
            ]);
        } catch (\Throwable $e) {
            $this->log->warning('order.placed.publish_failed', [
                'event_id' => $eventId,
                'error'    => $e->getMessage(),
            ]);
            return $eventId;
        }

        $this->outbox->markPublished($eventId, date(DATE_ATOM));
        $this->log->info('order.placed.published', ['event_id' => $eventId]);
        return $eventId;
    }
}
