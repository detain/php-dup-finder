<?php
declare(strict_types=1);

namespace App\Outbox\Shipments;

final class ShipmentDispatchedEvent
{
    public function __construct(public string $trackingNumber, public string $carrier, public int $shipmentId) {}
}

final class ShipmentOutbox
{
    public function __construct(private \PDO $pdo) {}

    public function record(ShipmentDispatchedEvent $event): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO shipment_outbox (event_type, payload, status, created_at)
             VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([
            'ShipmentDispatched',
            json_encode(['tracking' => $event->trackingNumber, 'carrier' => $event->carrier, 'shipmentId' => $event->shipmentId]),
            'pending',
        ]);
    }
}

final class ShipmentOutboxPublisher
{
    public function __construct(private \PDO $pdo, private \Psr\EventDispatcher\EventDispatcherInterface $bus) {}

    public function pump(int $batch = 50): int
    {
        $rows = $this->pdo->prepare('SELECT id, event_type, payload FROM shipment_outbox WHERE status = ? LIMIT ?');
        $rows->execute(['pending', $batch]);
        $found = $rows->fetchAll(\PDO::FETCH_ASSOC);
        $upd = $this->pdo->prepare('UPDATE shipment_outbox SET status = ?, published_at = NOW() WHERE id = ?');
        $count = 0;
        foreach ($found as $row) {
            $payload = json_decode((string) $row['payload'], true);
            $this->bus->dispatch(new ShipmentDispatchedEvent((string) $payload['tracking'], (string) $payload['carrier'], (int) $payload['shipmentId']));
            $upd->execute(['published', $row['id']]);
            $count++;
        }
        return $count;
    }
}
