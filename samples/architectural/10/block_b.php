<?php
declare(strict_types=1);

namespace App\Etl\Inventory;

final class InventoryRow
{
    public function __construct(public string $sku, public int $qty, public int $priceCents) {}
}

final class InventoryParser
{
    /** @return \Generator<int, array> */
    public function parse(string $path): \Generator
    {
        $raw = file_get_contents($path) ?: throw new \RuntimeException("cannot read {$path}");
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }
        foreach ($data as $item) {
            yield $item;
        }
    }
}

final class InventoryTransformer
{
    public function transform(array $raw): InventoryRow
    {
        return new InventoryRow(
            strtoupper(trim((string) ($raw['sku'] ?? ''))),
            (int) ($raw['quantity'] ?? 0),
            (int) round(((float) ($raw['price'] ?? 0.0)) * 100),
        );
    }
}

final class InventoryLoader
{
    /** @var list<InventoryRow> */
    private array $buffer = [];

    public function __construct(private \PDO $pdo, private int $batchSize = 100) {}

    public function load(InventoryRow $row): void
    {
        $this->buffer[] = $row;
        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare('INSERT INTO inventory (sku, qty, price_cents) VALUES (?, ?, ?)');
        foreach ($this->buffer as $row) {
            $stmt->execute([$row->sku, $row->qty, $row->priceCents]);
        }
        $this->pdo->commit();
        $this->buffer = [];
    }
}
