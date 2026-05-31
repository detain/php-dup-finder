<?php
declare(strict_types=1);

namespace Finance\Invoice;

final class InvoiceLine
{
    public function __construct(
        public readonly string $description,
        public readonly int $quantity,
        public readonly float $unitPrice,
    ) {}

    public function amount(): float { return round($this->quantity * $this->unitPrice, 2); }
}

final class Invoice
{
    /** @param InvoiceLine[] $lines */
    public function __construct(
        public readonly string $number,
        public readonly \DateTimeImmutable $issuedAt,
        public readonly \DateTimeImmutable $dueAt,
        public readonly string $customerName,
        public readonly string $customerAddress,
        public readonly array $lines,
        public readonly float $taxRate,
    ) {
        if ($number === '') throw new \InvalidArgumentException('Missing number');
    }

    public function subtotal(): float
    {
        return round(array_sum(array_map(fn($l) => $l->amount(), $this->lines)), 2);
    }

    public function tax(): float { return round($this->subtotal() * $this->taxRate, 2); }

    public function total(): float { return round($this->subtotal() + $this->tax(), 2); }

    public static function fromArray(array $data): self
    {
        $lines = [];
        foreach ($data['items'] ?? [] as $i) {
            $lines[] = new InvoiceLine((string)$i['description'], (int)$i['quantity'], (float)$i['unit_price']);
        }
        return new self(
            (string)$data['number'],
            new \DateTimeImmutable((string)$data['issued_at']),
            new \DateTimeImmutable((string)$data['due_at']),
            (string)$data['customer_name'],
            (string)$data['customer_address'],
            $lines,
            (float)$data['tax_rate'],
        );
    }
}
