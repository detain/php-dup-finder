<?php
declare(strict_types=1);

namespace Acme\Reports\Finance;

use Acme\Reports\PdfWriter;
use Acme\Reports\PeriodRange;
use Acme\Reports\ReportMeta;
use Acme\Finance\LedgerRepository;

final class FinanceReportGenerator
{
    public function __construct(
        private readonly LedgerRepository $ledger,
        private readonly PdfWriter $pdf
    ) {
    }

    public function generate(PeriodRange $range, string $outputPath): ReportMeta
    {
        $rows = $this->ledger->findInRange($range->start(), $range->end());
        if ($rows === []) {
            $this->pdf->writeEmpty($outputPath, 'General Ledger', $range);
            return new ReportMeta($outputPath, 0, 0.0);
        }

        $bucket = [];
        foreach ($rows as $row) {
            $key = $row->accountCode;
            $bucket[$key] ??= ['count' => 0, 'total' => 0.0];
            $bucket[$key]['count']++;
            $bucket[$key]['total'] += $row->signedAmount;
        }
        ksort($bucket);

        $this->pdf->open($outputPath);
        $this->pdf->title('General Ledger');
        $this->pdf->subtitle($range->label());
        $this->pdf->tableHeader(['Account', 'Entries', 'Balance']);

        $grandTotal = 0.0;
        $grandCount = 0;
        foreach ($bucket as $account => $agg) {
            $this->pdf->tableRow([
                $account,
                (string)$agg['count'],
                '$' . number_format($agg['total'], 2),
            ]);
            $grandTotal += $agg['total'];
            $grandCount += $agg['count'];
        }

        $this->pdf->tableFooter([
            'Totals',
            (string)$grandCount,
            '$' . number_format($grandTotal, 2),
        ]);
        $this->pdf->close();

        return new ReportMeta($outputPath, $grandCount, $grandTotal);
    }
}
