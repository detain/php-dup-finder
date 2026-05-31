<?php
declare(strict_types=1);

namespace Shop\Receipt;

final class ReceiptLine
{
    public string $sku;
    public string $description;
    public int $units;
    public string $unitPrice;
    public string $taxPercent;
    public string $subtotal;
    public string $tax;
    public string $total;

    public function __construct(array $data)
    {
        if ((int)$data['qty'] < 1) {
            throw new \InvalidArgumentException('Receipt line qty must be positive');
        }
        if ((float)$data['unit_price'] < 0) {
            throw new \InvalidArgumentException('Receipt line unit price negative');
        }
        $qty = (int)$data['qty'];
        $unit = (float)$data['unit_price'];
        $taxRate = (float)$data['tax_rate'];
        $subtotal = $qty * $unit;
        $tax = round($subtotal * $taxRate, 2);
        $total = $subtotal + $tax;

        $this->sku = (string)$data['sku'];
        $this->description = (string)$data['name'];
        $this->units = $qty;
        $this->unitPrice = number_format($unit, 2, '.', ',');
        $this->taxPercent = number_format($taxRate * 100, 2) . '%';
        $this->subtotal = number_format($subtotal, 2, '.', ',');
        $this->tax = number_format($tax, 2, '.', ',');
        $this->total = number_format($total, 2, '.', ',');
    }
}

final class ReceiptRenderer
{
    public function renderLines(array $rawLines): string
    {
        $out = '';
        foreach ($rawLines as $raw) {
            $line = new ReceiptLine($raw);
            $out .= sprintf("%s x%d @ %s = %s\n", $line->description, $line->units, $line->unitPrice, $line->total);
        }
        return $out;
    }
}
