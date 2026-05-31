<?php

declare(strict_types=1);

namespace Acme\Accounting\Export;

use Acme\Accounting\Model\Invoice;
use Acme\Accounting\Writer\CsvWriter;
use DateInterval;
use DateTimeImmutable;

final class AgedReceivablesExporter
{
    public function __construct(private CsvWriter $writer)
    {
    }

    /** @param iterable<Invoice> $invoices */
    public function exportOverdue(iterable $invoices, string $path): int
    {
        $rows = [];
        $now = new DateTimeImmutable();
        $grace = new DateInterval('P7D');

        foreach ($invoices as $invoice) {
            $cutoff = $invoice->dueDate()->add($grace);
            $balance = $invoice->outstandingBalance();

            if ($now > $cutoff && $balance > 0) {
                $rows[] = [
                    'invoice' => $invoice->number(),
                    'customer' => $invoice->customerName(),
                    'amount' => $balance,
                    'days_late' => $now->diff($cutoff)->days,
                ];
            }
        }

        $this->writer->writeAll($path, $rows);
        return count($rows);
    }
}
