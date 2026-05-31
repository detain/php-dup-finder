<?php
declare(strict_types=1);

namespace Shop\Orders\Persistence;

final class OrderLineRecord
{
    public int $id = 0;
    public int $orderId = 0;
    public string $product_sku = '';
    public string $product_name = '';
    public int $qty = 0;
    public float $unit_price = 0.0;
    public float $tax_rate = 0.0;
    public float $subtotal = 0.0;
    public float $tax_amount = 0.0;
    public float $total = 0.0;

    public static function fromDb(array $row): self
    {
        if ((int)$row['qty'] < 1) {
            throw new \RuntimeException('Persisted line has invalid qty');
        }
        if ((float)$row['unit_price'] < 0) {
            throw new \RuntimeException('Persisted line has negative price');
        }
        $self = new self();
        $self->id = (int)$row['id'];
        $self->orderId = (int)$row['order_id'];
        $self->product_sku = (string)$row['sku'];
        $self->product_name = (string)$row['name'];
        $self->qty = (int)$row['qty'];
        $self->unit_price = (float)$row['unit_price'];
        $self->tax_rate = (float)$row['tax_rate'];
        $self->subtotal = $self->qty * $self->unit_price;
        $self->tax_amount = round($self->subtotal * $self->tax_rate, 2);
        $self->total = $self->subtotal + $self->tax_amount;
        return $self;
    }
}

final class OrderLineRepository
{
    public function __construct(private \PDO $pdo) {}

    /** @return OrderLineRecord[] */
    public function forOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM order_lines WHERE order_id = ?');
        $stmt->execute([$orderId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = OrderLineRecord::fromDb($row);
        }
        return $out;
    }
}
