<?php
declare(strict_types=1);

namespace Acme\Reports\Sales;

use Acme\Reports\PdfWriter;
use Acme\Reports\PeriodRange;
use Acme\Reports\ReportMeta;
use Acme\Sales\OrderRepository;

final class SalesReportGenerator
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly PdfWriter $pdf
    ) {
    }

    public function generate(PeriodRange $range, string $outputPath): ReportMeta
    {
        $rows = $this->orders->findInRange($range->start(), $range->end());
        if ($rows === []) {
            $this->pdf->writeEmpty($outputPath, 'Sales Report', $range);
            return new ReportMeta($outputPath, 0, 0.0);
        }

        $bucket = [];
        foreach ($rows as $row) {
            $key = $row->placedAt->format('Y-m-d');
            $bucket[$key] ??= ['count' => 0, 'total' => 0.0];
            $bucket[$key]['count']++;
            $bucket[$key]['total'] += $row->amount;
        }
        ksort($bucket);

        $this->pdf->open($outputPath);
        $this->pdf->title('Sales Report');
        $this->pdf->subtitle($range->label());
        $this->pdf->tableHeader(['Date', 'Orders', 'Revenue']);

        $grandTotal = 0.0;
        $grandCount = 0;
        foreach ($bucket as $date => $agg) {
            $this->pdf->tableRow([
                $date,
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
