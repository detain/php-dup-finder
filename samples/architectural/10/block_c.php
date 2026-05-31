<?php
declare(strict_types=1);

namespace App\Etl\Invoices;

final class InvoiceRow
{
    public function __construct(public string $number, public string $customer, public int $totalCents) {}
}

final class InvoiceParser
{
    /** @return \Generator<int, array> */
    public function parse(string $path): \Generator
    {
        $xml = simplexml_load_file($path);
        if ($xml === false) {
            return;
        }
        foreach ($xml->invoice as $node) {
            yield [
                'number' => (string) $node->number,
                'customer' => (string) $node->customer,
                'total' => (string) $node->total,
            ];
        }
    }
}

final class InvoiceTransformer
{
    public function transform(array $raw): InvoiceRow
    {
        return new InvoiceRow(
            trim((string) ($raw['number'] ?? '')),
            trim((string) ($raw['customer'] ?? '')),
            (int) round(((float) ($raw['total'] ?? 0.0)) * 100),
        );
    }
}

final class InvoiceLoader
{
    /** @var list<InvoiceRow> */
    private array $buffer = [];

    public function __construct(private \PDO $pdo, private int $batchSize = 100) {}

    public function load(InvoiceRow $row): void
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
        $stmt = $this->pdo->prepare('INSERT INTO invoices (number, customer, total_cents) VALUES (?, ?, ?)');
        foreach ($this->buffer as $row) {
            $stmt->execute([$row->number, $row->customer, $row->totalCents]);
        }
        $this->pdo->commit();
        $this->buffer = [];
    }
}
