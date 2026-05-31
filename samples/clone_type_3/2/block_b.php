<?php

declare(strict_types=1);

namespace App\Export\Excel;

use App\Entity\Report;
use App\Repository\ReportRepository;
use App\Service\ExcelWriter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportExcelExporter
{
    public function __construct(
        private readonly ReportRepository $reportRepository,
        private readonly ExcelWriter $excelWriter,
        private readonly LoggerInterface $logger,
    ) {}

    public function exportToStream(int $reportId): StreamedResponse
    {
        $report = $this->reportRepository->findById($reportId);

        if ($report === null) {
            throw new \RuntimeException("Report {$reportId} not found");
        }

        $this->logger->info('Starting Excel export for report', [
            'report_id' => $reportId,
            'format' => 'excel',
        ]);

        $response = new StreamedResponse(function () use ($report) {
            $this->excelWriter->createSpreadsheet();

            $this->excelWriter->addHeaderRow([
                'Report ID',
                'Title',
                'Status',
                'Created Date',
                'Total Amount',
                'Department',
                'Category',
                'Cost Center',
            ]);

            foreach ($report->getLineItems() as $item) {
                $this->excelWriter->addDataRow([
                    $report->getId(),
                    $report->getTitle(),
                    $report->getStatus(),
                    $report->getCreatedAt()->format('Y-m-d'),
                    number_format($item->getAmount(), 2),
                    $item->getDepartment(),
                    $item->getCategory(),
                    $item->getCostCenter(),
                ]);
            }

            $this->excelWriter->outputSpreadsheet();
        });

        $filename = sprintf('report_%d_%s.xlsx', $reportId, date('Ymd'));
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");

        $this->logger->info('Excel export completed', [
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

        $this->excelWriter->createSpreadsheet();

        $this->excelWriter->addHeaderRow([
            'Report ID',
            'Title',
            'Status',
            'Created Date',
            'Total Amount',
            'Department',
            'Category',
            'Cost Center',
        ]);

        foreach ($report->getLineItems() as $item) {
            $this->excelWriter->addDataRow([
                $report->getId(),
                $report->getTitle(),
                $report->getStatus(),
                $report->getCreatedAt()->format('Y-m-d'),
                number_format($item->getAmount(), 2),
                $item->getDepartment(),
                $item->getCategory(),
                $item->getCostCenter(),
            ]);
        }

        $this->excelWriter->saveToFile($filePath);

        $this->logger->info('Excel file exported', [
            'report_id' => $reportId,
            'file_path' => $filePath,
        ]);
    }
}
