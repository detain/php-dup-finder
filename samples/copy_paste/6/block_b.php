<?php
declare(strict_types=1);

namespace Acme\Reports\Invoices;

final class InvoiceExporter
{
    public function exportRows(array $invoices): string
    {
        $buffer = "invoice_no,customer,amount,status\n";

        foreach ($invoices as $inv) {
            $row = [
                $inv->number(),
                $inv->customerName(),
                number_format($inv->amount(), 2, '.', ''),
                $inv->status(),
            ];

            // ---- BEGIN copy-pasted CSV row builder ----
            $cleaned = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $cleaned[] = '';
                    continue;
                }
                $str = (string) $value;
                $str = str_replace(["\r\n", "\r"], "\n", $str);
                $needsQuote = preg_match('/[",\n]/', $str) === 1;
                $escaped = str_replace('"', '""', $str);
                $cleaned[] = $needsQuote ? '"' . $escaped . '"' : $escaped;
            }
            $line = implode(',', $cleaned) . "\n";
            // ---- END copy-pasted CSV row builder ----

            $buffer .= $line;
        }
        return $buffer;
    }
}
