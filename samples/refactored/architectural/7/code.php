<?php
declare(strict_types=1);

namespace App\Domain;

abstract class Aggregate
{
    /** @var list<object> */
    private array $events = [];

    protected function record(object $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<object> */
    public function pullEvents(): array
    {
        $e = $this->events;
        $this->events = [];
        return $e;
    }

    /** @return array<string, scalar> */
    abstract public function toRow(): array;

    abstract public function id(): string;
}

/** @template T of Aggregate */
final class AggregateRepository
{
    /** @param callable(array): T $hydrator */
    public function __construct(
        private \PDO $pdo,
        private string $table,
        private \Closure $hydrator,
    ) {}

    public function save(Aggregate $agg): void
    {
        $row = $agg->toRow();
        $cols = implode(',', array_keys($row));
        $placeholders = implode(',', array_fill(0, count($row), '?'));
        $stmt = $this->pdo->prepare("REPLACE INTO {$this->table} ({$cols}) VALUES ({$placeholders})");
        $stmt->execute(array_values($row));
    }

    public function load(string $id): ?Aggregate
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? ($this->hydrator)($row) : null;
    }
}
