<?php
declare(strict_types=1);

namespace App\Outbox;

interface OutboxEvent
{
    public function type(): string;

    /** @return array<string, scalar> */
    public function payload(): array;

    /** @param array<string, scalar> $payload */
    public static function fromPayload(array $payload): self;
}

final class Outbox
{
    public function __construct(private \PDO $pdo, private string $table) {}

    public function record(OutboxEvent $event): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (event_type, payload, status, created_at)
             VALUES (?, ?, 'pending', NOW())"
        );
        $stmt->execute([$event->type(), json_encode($event->payload())]);
    }
}

final class OutboxPublisher
{
    /** @param array<string, class-string<OutboxEvent>> $eventMap */
    public function __construct(
        private \PDO $pdo,
        private \Psr\EventDispatcher\EventDispatcherInterface $bus,
        private string $table,
        private array $eventMap,
    ) {}

    public function pump(int $batch = 50): int
    {
        $rows = $this->pdo->prepare("SELECT id, event_type, payload FROM {$this->table} WHERE status = 'pending' LIMIT ?");
        $rows->execute([$batch]);
        $upd = $this->pdo->prepare("UPDATE {$this->table} SET status = 'published', published_at = NOW() WHERE id = ?");
        $count = 0;
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $class = $this->eventMap[(string) $row['event_type']] ?? throw new \RuntimeException('unknown event');
            $this->bus->dispatch($class::fromPayload(json_decode((string) $row['payload'], true)));
            $upd->execute([$row['id']]);
            $count++;
        }
        return $count;
    }
}
