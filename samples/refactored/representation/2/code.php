<?php
declare(strict_types=1);

namespace Shop\OrderLine;

final class Money
{
    public function __construct(public readonly int $cents) {}
    public function format(): string { return number_format($this->cents / 100, 2, '.', ','); }
}

final class OrderLine
{
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly int $quantity,
        public readonly Money $unitPrice,
        public readonly int $taxRateBps,
    ) {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be >= 1');
        }
        if ($unitPrice->cents < 0) {
            throw new \InvalidArgumentException('Price must be non-negative');
        }
    }

    public function subtotal(): Money
    {
        return new Money($this->quantity * $this->unitPrice->cents);
    }

    public function tax(): Money
    {
        return new Money((int) round($this->subtotal()->cents * $this->taxRateBps / 10000));
    }

    public function total(): Money
    {
        return new Money($this->subtotal()->cents + $this->tax()->cents);
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (string)$row['sku'],
            (string)$row['name'],
            (int)$row['qty'],
            new Money((int) round(((float)$row['unit_price']) * 100)),
            (int) round(((float)$row['tax_rate']) * 10000),
        );
    }
}
