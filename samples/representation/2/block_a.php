<?php
declare(strict_types=1);

namespace Shop\Cart;

final class CartEntry
{
    public string $sku;
    public string $name;
    public int $quantity;
    public int $unitPriceCents;
    public int $taxRateBps;
    public int $lineSubtotalCents;
    public int $lineTaxCents;
    public int $lineTotalCents;

    public function __construct(string $sku, string $name, int $quantity, int $unitPriceCents, int $taxRateBps)
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be >= 1');
        }
        if ($unitPriceCents < 0) {
            throw new \InvalidArgumentException('Price must be non-negative');
        }
        $this->sku = $sku;
        $this->name = $name;
        $this->quantity = $quantity;
        $this->unitPriceCents = $unitPriceCents;
        $this->taxRateBps = $taxRateBps;
        $this->lineSubtotalCents = $quantity * $unitPriceCents;
        $this->lineTaxCents = (int) round($this->lineSubtotalCents * $taxRateBps / 10000);
        $this->lineTotalCents = $this->lineSubtotalCents + $this->lineTaxCents;
    }

    public function bumpQuantity(int $delta): void
    {
        $this->quantity += $delta;
        $this->lineSubtotalCents = $this->quantity * $this->unitPriceCents;
        $this->lineTaxCents = (int) round($this->lineSubtotalCents * $this->taxRateBps / 10000);
        $this->lineTotalCents = $this->lineSubtotalCents + $this->lineTaxCents;
    }
}

final class CartService
{
    /** @var CartEntry[] */
    private array $entries = [];

    public function add(string $sku, string $name, int $qty, int $priceCents, int $taxBps): void
    {
        $this->entries[] = new CartEntry($sku, $name, $qty, $priceCents, $taxBps);
    }
}
