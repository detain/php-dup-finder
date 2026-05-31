<?php

declare(strict_types=1);

namespace App\Formatting\Csv;

use App\Entity\Report;
use App\Repository\ReportRepository;
use Psr\Log\LoggerInterface;

final class ReportCsvFormatter
{
    public function __construct(
        private readonly ReportRepository $reportRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function formatReport(int $reportId): string
    {
        $report = $this->reportRepository->findById($reportId);

        if ($report === null) {
            throw new \RuntimeException("Report {$reportId} not found");
        }

        $this->logger->info('Formatting report to CSV', [
            'report_id' => $reportId,
            'format' => 'csv',
        ]);

        $lines = [];

        $lines[] = 'id,title,created_at,status';

        $lines[] = sprintf(
            '%d,%s,%s,%s',
            $report->getId(),
            $this->escapeCsv($report->getTitle()),
            $report->getCreatedAt()->format('c'),
            $report->getStatus()
        );

        foreach ($report->getLineItems() as $lineItem) {
            $lines[] = sprintf(
                '%d,%s,%.2f,%s,%s',
                $lineItem->getId(),
                $this->escapeCsv($lineItem->getDescription()),
                $lineItem->getAmount(),
                $this->escapeCsv($lineItem->getCategory()),
                $lineItem->getDate()->format('Y-m-d')
            );
        }

        $csv = implode("\n", $lines);

        $this->logger->info('Report formatted to CSV successfully', [
            'report_id' => $reportId,
            'size_bytes' => strlen($csv),
        ]);

        return $csv;
    }

    public function formatReportSummary(int $reportId): string
    {
        $report = $this->reportRepository->findById($reportId);

        if ($report === null) {
            throw new \RuntimeException("Report {$reportId} not found");
        }

        return sprintf(
            "id,title,item_count,total_amount,generated_at\n%d,%s,%d,%.2f,%s",
            $report->getId(),
            $this->escapeCsv($report->getTitle()),
            count($report->getLineItems()),
            $this->calculateTotal($report),
            (new \DateTimeImmutable())->format('c')
        );
    }

    private function calculateTotal(Report $report): float
    {
        return array_reduce(
            $report->getLineItems(),
            fn(float $carry, $item) => $carry + $item->getAmount(),
            0.0
        );
    }

    private function escapeCsv(string $value): string
    {
        return str_replace('"', '""', $value);
    }
}
