<?php

declare(strict_types=1);

namespace App\Export\Csv;

use App\Entity\Report;
use App\Repository\ReportRepository;
use App\Service\CsvWriter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportCsvExporter
{
    public function __construct(
        private readonly ReportRepository $reportRepository,
        private readonly CsvWriter $csvWriter,
        private readonly LoggerInterface $logger,
    ) {}

    public function exportToStream(int $reportId): StreamedResponse
    {
        $report = $this->reportRepository->findById($reportId);

        if ($report === null) {
            throw new \RuntimeException("Report {$reportId} not found");
        }

        $this->logger->info('Starting CSV export for report', [
            'report_id' => $reportId,
            'format' => 'csv',
        ]);

        $response = new StreamedResponse(function () use ($report) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Report ID',
                'Title',
                'Status',
                'Created Date',
                'Total Amount',
                'Department',
            ]);

            foreach ($report->getLineItems() as $item) {
                fputcsv($handle, [
                    $report->getId(),
                    $report->getTitle(),
                    $report->getStatus(),
                    $report->getCreatedAt()->format('Y-m-d'),
                    number_format($item->getAmount(), 2),
                    $item->getDepartment(),
                ]);
            }

            fclose($handle);
        });

        $filename = sprintf('report_%d_%s.csv', $reportId, date('Ymd'));
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");

        $this->logger->info('CSV export completed', [
            'report_id' => $reportId,
            'filename' => $filename,
        ]);

        return $response;
    }

    public function exportToFile(int $reportId, string $filePath): void
    {
        $report = $this->reportRepository->findById($reportId);

        if ($report === null) {
            throw new \RuntimeException("Report {$reportId} not found");
        }

        $handle = fopen($filePath, 'w');

        fputcsv($handle, [
            'Report ID',
            'Title',
            'Status',
            'Created Date',
            'Total Amount',
            'Department',
        ]);

        foreach ($report->getLineItems() as $item) {
            fputcsv($handle, [
                $report->getId(),
                $report->getTitle(),
                $report->getStatus(),
                $report->getCreatedAt()->format('Y-m-d'),
                number_format($item->getAmount(), 2),
                $item->getDepartment(),
            ]);
        }

        fclose($handle);

        $this->logger->info('CSV file exported', [
            'report_id' => $reportId,
            'file_path' => $filePath,
        ]);
    }
}
