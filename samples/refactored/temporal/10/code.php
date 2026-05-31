<?php
declare(strict_types=1);

namespace ECommerce\Events;

use ECommerce\Outbox\OutboxRepository;
use ECommerce\Messaging\Publisher;
use Psr\Log\LoggerInterface;

final class OutboxEmitter
{
    public function __construct(
        private OutboxRepository $outbox,
        private Publisher $publisher,
        private LoggerInterface $log,
    ) {}

    /**
     * @param array<string,mixed> $payload
     */
    public function publishViaOutbox(string $aggregate, string $aggregateId, string $type, array $payload): string
    {
        $eventId = bin2hex(random_bytes(16));
        $this->outbox->insert([
            'event_id'    => $eventId,
            'aggregate'   => $aggregate,
            'aggregate_id'=> $aggregateId,
            'type'        => $type,
            'payload'     => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at'  => date(DATE_ATOM),
            'published_at'=> null,
        ]);

        try {
            $this->publisher->publish($type, [
                'event_id'     => $eventId,
                'aggregate_id' => $aggregateId,
                'data'         => $payload,
            ]);
        } catch (\Throwable $e) {
            $this->log->warning("{$type}.publish_failed", [
                'event_id' => $eventId,
                'error'    => $e->getMessage(),
            ]);
            return $eventId;
        }

        $this->outbox->markPublished($eventId, date(DATE_ATOM));
        $this->log->info("{$type}.published", ['event_id' => $eventId]);
        return $eventId;
    }
}

final class OrderPlacedEmitter
{
    public function __construct(private OutboxEmitter $emitter) {}

    public function emit(string $orderId, array $payload): string
    {
        return $this->emitter->publishViaOutbox('order', $orderId, 'order.placed', $payload);
    }
}
