<?php
declare(strict_types=1);

namespace Acme\Reports\Inventory;

use Acme\Reports\PdfWriter;
use Acme\Reports\PeriodRange;
use Acme\Reports\ReportMeta;
use Acme\Inventory\MovementRepository;

final class InventoryReportGenerator
{
    public function __construct(
        private readonly MovementRepository $movements,
        private readonly PdfWriter $pdf
    ) {
    }

    public function generate(PeriodRange $range, string $outputPath): ReportMeta
    {
        $rows = $this->movements->findInRange($range->start(), $range->end());
        if ($rows === []) {
            $this->pdf->writeEmpty($outputPath, 'Inventory Movements', $range);
            return new ReportMeta($outputPath, 0, 0.0);
        }

        $bucket = [];
        foreach ($rows as $row) {
            $key = $row->sku;
            $bucket[$key] ??= ['count' => 0, 'total' => 0.0];
            $bucket[$key]['count']++;
            $bucket[$key]['total'] += $row->quantityDelta;
        }
        ksort($bucket);

        $this->pdf->open($outputPath);
        $this->pdf->title('Inventory Movements');
        $this->pdf->subtitle($range->label());
        $this->pdf->tableHeader(['SKU', 'Moves', 'Net Units']);

        $grandTotal = 0.0;
        $grandCount = 0;
        foreach ($bucket as $sku => $agg) {
            $this->pdf->tableRow([
                $sku,
                (string)$agg['count'],
                number_format($agg['total'], 0),
            ]);
            $grandTotal += $agg['total'];
            $grandCount += $agg['count'];
        }

        $this->pdf->tableFooter([
            'Totals',
            (string)$grandCount,
            number_format($grandTotal, 0),
        ]);
        $this->pdf->close();

        return new ReportMeta($outputPath, $grandCount, $grandTotal);
    }
}
