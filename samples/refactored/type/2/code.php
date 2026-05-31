<?php
declare(strict_types=1);

namespace Acme\Reports;

interface ReportSource
{
    public function title(): string;
    /** @return array<string> */
    public function columns(): array;
    /** @param iterable<object> $rows */
    public function fetch(PeriodRange $range): iterable;
    public function groupKey(object $row): string;
    public function delta(object $row): float;
    public function formatGroup(string $key, int $count, float $total): array;
    public function formatTotals(int $count, float $total): array;
}

final class ReportGenerator
{
    public function __construct(private readonly PdfWriter $pdf)
    {
    }

    public function generate(ReportSource $source, PeriodRange $range, string $outputPath): ReportMeta
    {
        $rows = $source->fetch($range);
        $bucket = [];
        foreach ($rows as $row) {
            $key = $source->groupKey($row);
            $bucket[$key] ??= ['count' => 0, 'total' => 0.0];
            $bucket[$key]['count']++;
            $bucket[$key]['total'] += $source->delta($row);
        }

        if ($bucket === []) {
            $this->pdf->writeEmpty($outputPath, $source->title(), $range);
            return new ReportMeta($outputPath, 0, 0.0);
        }
        ksort($bucket);

        $this->pdf->open($outputPath);
        $this->pdf->title($source->title());
        $this->pdf->subtitle($range->label());
        $this->pdf->tableHeader($source->columns());

        $grandTotal = 0.0;
        $grandCount = 0;
        foreach ($bucket as $key => $agg) {
            $this->pdf->tableRow($source->formatGroup($key, $agg['count'], $agg['total']));
            $grandTotal += $agg['total'];
            $grandCount += $agg['count'];
        }
        $this->pdf->tableFooter($source->formatTotals($grandCount, $grandTotal));
        $this->pdf->close();

        return new ReportMeta($outputPath, $grandCount, $grandTotal);
    }
}
