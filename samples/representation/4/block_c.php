<?php
declare(strict_types=1);

namespace Finance\Accounting\Export;

final class InvoiceExportRow
{
    public string $inv_no;
    public string $inv_issued;
    public string $inv_due;
    public string $cust_name;
    public string $cust_addr;
    /** @var array<int, array{line_desc:string, line_qty:int, line_unit:float, line_amt:float}> */
    public array $line_items;
    public float $net_amount;
    public float $tax_amount;
    public float $gross_amount;

    public function fromInvoice(array $data): void
    {
        if (empty($data['number'])) {
            throw new \InvalidArgumentException('Export row needs number');
        }
        $this->inv_no = (string)$data['number'];
        $this->inv_issued = (new \DateTimeImmutable((string)$data['issued_at']))->format('d/m/Y');
        $this->inv_due = (new \DateTimeImmutable((string)$data['due_at']))->format('d/m/Y');
        $this->cust_name = (string)$data['customer_name'];
        $this->cust_addr = str_replace("\n", ' / ', (string)$data['customer_address']);
        $this->line_items = [];
        $sub = 0.0;
        foreach ($data['items'] ?? [] as $item) {
            $amt = (int)$item['quantity'] * (float)$item['unit_price'];
            $this->line_items[] = [
                'line_desc' => (string)$item['description'],
                'line_qty' => (int)$item['quantity'],
                'line_unit' => (float)$item['unit_price'],
                'line_amt' => round($amt, 2),
            ];
            $sub += $amt;
        }
        $this->net_amount = round($sub, 2);
        $this->tax_amount = round($sub * (float)$data['tax_rate'], 2);
        $this->gross_amount = round($this->net_amount + $this->tax_amount, 2);
    }
}

final class CsvExporter
{
    public function export(array $rawInvoices): string
    {
        $csv = "inv_no,inv_issued,gross_amount\n";
        foreach ($rawInvoices as $raw) {
            $row = new InvoiceExportRow();
            $row->fromInvoice($raw);
            $csv .= "{$row->inv_no},{$row->inv_issued},{$row->gross_amount}\n";
        }
        return $csv;
    }
}
