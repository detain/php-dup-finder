<?php
declare(strict_types=1);

namespace Finance\Pdf;

final class InvoicePdfModel
{
    public string $invoiceNumber;
    public string $issueDate;
    public string $dueDate;
    public string $billTo;
    public string $billToAddress;
    /** @var array<int, array{desc:string, qty:int, unit:float, total:float}> */
    public array $lines;
    public float $subtotal;
    public float $taxAmount;
    public float $grandTotal;

    public function __construct(array $data)
    {
        if (empty($data['number'])) {
            throw new \InvalidArgumentException('Missing invoice number');
        }
        $this->invoiceNumber = (string)$data['number'];
        $this->issueDate = (new \DateTimeImmutable((string)$data['issued_at']))->format('F j, Y');
        $this->dueDate = (new \DateTimeImmutable((string)$data['due_at']))->format('F j, Y');
        $this->billTo = (string)$data['customer_name'];
        $this->billToAddress = (string)$data['customer_address'];
        $this->lines = [];
        $sub = 0.0;
        foreach ($data['items'] ?? [] as $item) {
            $line = [
                'desc' => (string)$item['description'],
                'qty' => (int)$item['quantity'],
                'unit' => (float)$item['unit_price'],
                'total' => (int)$item['quantity'] * (float)$item['unit_price'],
            ];
            $this->lines[] = $line;
            $sub += $line['total'];
        }
        $this->subtotal = round($sub, 2);
        $this->taxAmount = round($sub * (float)$data['tax_rate'], 2);
        $this->grandTotal = round($this->subtotal + $this->taxAmount, 2);
    }
}

final class PdfRenderer
{
    public function render(InvoicePdfModel $model): string
    {
        return "INVOICE {$model->invoiceNumber}\nTotal: {$model->grandTotal}\n";
    }
}
