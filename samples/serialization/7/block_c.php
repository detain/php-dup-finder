<?php

declare(strict_types=1);

namespace App\Export;

class OrderCsvExporter
{
    private string $delimiter = ',';
    private string $enclosure = '"';
    private string $lineEnding = "\r\n";

    public function export(array $orders, string $filepath): int
    {
        $handle = fopen($filepath, 'w');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open file for writing: {$filepath}");
        }

        $this->writeHeader($handle);
        $rowCount = 0;

        foreach ($orders as $order) {
            $this->writeRow($handle, $order);
            $rowCount++;
        }

        fclose($handle);

        return $rowCount;
    }

    public function exportToString(array $orders): string
    {
        $handle = fopen('php://memory', 'r+');

        $this->writeHeader($handle);

        foreach ($orders as $order) {
            $this->writeRow($handle, $order);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    private function writeHeader($handle): void
    {
        $headers = [
            'ID',
            'User ID',
            'Total Amount',
            'Total Currency',
            'Status',
            'Shipping Address',
            'Billing Address',
            'Item Count',
            'Created At',
            'Updated At',
            'Shipped At'
        ];

        fputcsv($handle, $headers, $this->delimiter, $this->enclosure, $this->lineEnding);
    }

    private function writeRow($handle, Order $order): void
    {
        $row = [
            $order->getId(),
            $order->getUserId(),
            (string)$order->getTotalAmount(),
            $order->getCurrency(),
            $order->getStatus(),
            $order->getShippingAddress() ?? '',
            $order->getBillingAddress() ?? '',
            (string)count($order->getItems()),
            $order->getCreatedAt()->format('Y-m-d H:i:s'),
            $order->getUpdatedAt()?->format('Y-m-d H:i:s') ?? '',
            $order->getShippedAt()?->format('Y-m-d H:i:s') ?? ''
        ];

        fputcsv($handle, $row, $this->delimiter, $this->enclosure, $this->lineEnding);
    }

    public function generateFilename(string $prefix = 'export'): string
    {
        $timestamp = date('Y-m-d_His');
        return "{$prefix}_{$timestamp}.csv";
    }

    public function setDelimiter(string $delimiter): void
    {
        $this->delimiter = $delimiter;
    }

    public function setEnclosure(string $enclosure): void
    {
        $this->enclosure = $enclosure;
    }

    public function getContentType(): string
    {
        return 'text/csv; charset=utf-8';
    }
}
