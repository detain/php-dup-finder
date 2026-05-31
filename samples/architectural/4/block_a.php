<?php
declare(strict_types=1);

namespace App\Outbox\Orders;

final class OrderPlacedEvent
{
    public function __construct(public int $orderId, public string $customerEmail, public int $totalCents) {}
}

final class OrderOutbox
{
    public function __construct(private \PDO $pdo) {}

    public function record(OrderPlacedEvent $event): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_outbox (event_type, payload, status, created_at)
             VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([
            'OrderPlaced',
            json_encode(['orderId' => $event->orderId, 'email' => $event->customerEmail, 'total' => $event->totalCents]),
            'pending',
        ]);
    }
}

final class OrderOutboxPublisher
{
    public function __construct(private \PDO $pdo, private \Psr\EventDispatcher\EventDispatcherInterface $bus) {}

    public function pump(int $batch = 50): int
    {
        $rows = $this->pdo->prepare('SELECT id, event_type, payload FROM order_outbox WHERE status = ? LIMIT ?');
        $rows->execute(['pending', $batch]);
        $found = $rows->fetchAll(\PDO::FETCH_ASSOC);
        $upd = $this->pdo->prepare('UPDATE order_outbox SET status = ?, published_at = NOW() WHERE id = ?');
        $count = 0;
        foreach ($found as $row) {
            $payload = json_decode((string) $row['payload'], true);
            $this->bus->dispatch(new OrderPlacedEvent((int) $payload['orderId'], (string) $payload['email'], (int) $payload['total']));
            $upd->execute(['published', $row['id']]);
            $count++;
        }
        return $count;
    }
}
