<?php
declare(strict_types=1);

namespace Finance\Mail;

final class InvoiceEmailModel
{
    public function __construct(
        public readonly string $number,
        public readonly string $issued,
        public readonly string $due,
        public readonly string $recipient,
        public readonly string $address,
        public readonly array $items,
        public readonly float $subtotal,
        public readonly float $tax,
        public readonly float $total,
    ) {}

    public static function build(array $data): self
    {
        if (empty($data['number'])) {
            throw new \InvalidArgumentException('Missing invoice number');
        }
        $items = [];
        $sub = 0.0;
        foreach ($data['items'] ?? [] as $item) {
            $rowTotal = (int)$item['quantity'] * (float)$item['unit_price'];
            $items[] = [
                'name' => (string)$item['description'],
                'units' => (int)$item['quantity'],
                'rate' => (float)$item['unit_price'],
                'amount' => round($rowTotal, 2),
            ];
            $sub += $rowTotal;
        }
        $tax = round($sub * (float)$data['tax_rate'], 2);
        return new self(
            (string)$data['number'],
            (new \DateTimeImmutable((string)$data['issued_at']))->format('Y-m-d'),
            (new \DateTimeImmutable((string)$data['due_at']))->format('Y-m-d'),
            (string)$data['customer_name'],
            (string)$data['customer_address'],
            $items,
            round($sub, 2),
            $tax,
            round($sub + $tax, 2),
        );
    }
}

final class InvoiceMailer
{
    public function send(InvoiceEmailModel $model, string $to): bool
    {
        return mail($to, "Invoice {$model->number}", "Total due: {$model->total}") !== false;
    }
}
