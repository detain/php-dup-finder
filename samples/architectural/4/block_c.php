<?php
declare(strict_types=1);

namespace App\Outbox\Invoices;

final class InvoiceIssuedEvent
{
    public function __construct(public int $invoiceId, public string $customerId, public int $amountCents) {}
}

final class InvoiceOutbox
{
    public function __construct(private \PDO $pdo) {}

    public function record(InvoiceIssuedEvent $event): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO invoice_outbox (event_type, payload, status, created_at)
             VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([
            'InvoiceIssued',
            json_encode(['invoiceId' => $event->invoiceId, 'customer' => $event->customerId, 'amount' => $event->amountCents]),
            'pending',
        ]);
    }
}

final class InvoiceOutboxPublisher
{
    public function __construct(private \PDO $pdo, private \Psr\EventDispatcher\EventDispatcherInterface $bus) {}

    public function pump(int $batch = 50): int
    {
        $rows = $this->pdo->prepare('SELECT id, event_type, payload FROM invoice_outbox WHERE status = ? LIMIT ?');
        $rows->execute(['pending', $batch]);
        $found = $rows->fetchAll(\PDO::FETCH_ASSOC);
        $upd = $this->pdo->prepare('UPDATE invoice_outbox SET status = ?, published_at = NOW() WHERE id = ?');
        $count = 0;
        foreach ($found as $row) {
            $payload = json_decode((string) $row['payload'], true);
            $this->bus->dispatch(new InvoiceIssuedEvent((int) $payload['invoiceId'], (string) $payload['customer'], (int) $payload['amount']));
            $upd->execute(['published', $row['id']]);
            $count++;
        }
        return $count;
    }
}
