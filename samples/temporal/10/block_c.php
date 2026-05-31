<?php
declare(strict_types=1);

namespace ECommerce\Events\Shipping;

use ECommerce\Outbox\OutboxRepository;
use ECommerce\Messaging\Publisher;
use Psr\Log\LoggerInterface;

final class ShipmentDispatchedEmitter
{
    public function __construct(
        private OutboxRepository $outbox,
        private Publisher $publisher,
        private LoggerInterface $log,
    ) {}

    /**
     * @param array<string,mixed> $payload
     */
    public function emit(string $shipmentId, array $payload): string
    {
        $eventId = bin2hex(random_bytes(16));
        $this->outbox->insert([
            'event_id'    => $eventId,
            'aggregate'   => 'shipment',
            'aggregate_id'=> $shipmentId,
            'type'        => 'shipment.dispatched',
            'payload'     => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at'  => date(DATE_ATOM),
            'published_at'=> null,
        ]);

        try {
            $this->publisher->publish('shipment.dispatched', [
                'event_id'    => $eventId,
                'shipment_id' => $shipmentId,
                'data'        => $payload,
            ]);
        } catch (\Throwable $e) {
            $this->log->warning('shipment.dispatched.publish_failed', [
                'event_id' => $eventId,
                'error'    => $e->getMessage(),
            ]);
            return $eventId;
        }

        $this->outbox->markPublished($eventId, date(DATE_ATOM));
        $this->log->info('shipment.dispatched.published', ['event_id' => $eventId]);
        return $eventId;
    }
}
