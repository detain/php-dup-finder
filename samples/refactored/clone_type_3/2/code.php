<?php

declare(strict_types=1);

namespace App\Export;

use App\Entity\Report;
use App\Repository\ReportRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface ExporterInterface
{
    public function supports(string $format): bool;
    public function exportToStream(int $reportId): StreamedResponse;
    public function exportToFile(int $reportId, string $filePath): void;
}

abstract class AbstractReportExporter implements ExporterInterface
{
    public function __construct(
        protected readonly ReportRepository $reportRepository,
        protected readonly LoggerInterface $logger,
    ) {}

    public function exportToStream(int $reportId): StreamedResponse
    {
        $report = $this->getReport($reportId);
        $this->logExportStart($reportId);

        $response = new StreamedResponse(function () use ($report) {
            $this->writeHeader();
            $this->writeItems($report);
        });

        $this->configureResponse($response, $reportId);
        $this->logExportComplete($reportId);

        return $response;
    }

    public function exportToFile(int $reportId, string $filePath): void
    {
        $report = $this->getReport($reportId);
        $this->writeItemsToFile($report, $filePath);
        $this->logFileExported($reportId, $filePath);
    }

    protected function getReport(int $reportId): Report
    {
        $report = $this->reportRepository->findById($reportId);

        if ($report === null) {
            throw new \RuntimeException("Report {$reportId} not found");
        }

        return $report;
    }

    abstract protected function writeHeader(): void;
    abstract protected function writeItem(Report $report, $item): void;
    abstract protected function getContentType(): string;
    abstract protected function getFileExtension(): string;

    protected function writeItems(Report $report): void
    {
        foreach ($report->getLineItems() as $item) {
            $this->writeItem($report, $item);
        }
    }

    protected function writeItemsToFile(Report $report, string $filePath): void
    {
        $handle = fopen($filePath, 'w');
        $this->writeHeaderToHandle($handle);
        foreach ($report->getLineItems() as $item) {
            $this->writeItemToHandle($handle, $report, $item);
        }
        fclose($handle);
    }

    protected function configureResponse(StreamedResponse $response, int $reportId): void
    {
        $filename = sprintf('report_%d_%s.%s', $reportId, date('Ymd'), $this->getFileExtension());
        $response->headers->set('Content-Type', $this->getContentType());
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    protected function logExportStart(int $reportId): void
    {
        $this->logger->info("Starting {$this->getFileExtension()} export for report", [
            'report_id' => $reportId,
            'format' => $this->getFileExtension(),
        ]);
    }

    protected function logExportComplete(int $reportId): void
    {
        $this->logger->info("{$this->getFileExtension()} export completed", [
            'report_id' => $reportId,
            'filename' => sprintf('report_%d_%s.%s', $reportId, date('Ymd'), $this->getFileExtension()),
        ]);
    }

    protected function logFileExported(int $reportId, string $filePath): void
    {
        $this->logger->info("{$this->getFileExtension()} file exported", [
            'report_id' => $reportId,
            'file_path' => $filePath,
        ]);
    }

    protected function writeHeaderToHandle($handle): void
    {
        foreach ($this->getColumnHeaders() as $header) {
            fputcsv($handle, $header);
        }
    }

    protected function writeItemToHandle($handle, Report $report, $item): void
    {
        $row = $this->buildRow($report, $item);
        fputcsv($handle, $row);
    }

    protected function buildRow(Report $report, $item): array
    {
        return [
            $report->getId(),
            $report->getTitle(),
            $report->getStatus(),
            $report->getCreatedAt()->format('Y-m-d'),
            number_format($item->getAmount(), 2),
            $item->getDepartment(),
        ];
    }

    public function getColumnHeaders(): array
    {
        return [
            ['Report ID', 'Title', 'Status', 'Created Date', 'Total Amount', 'Department'],
        ];
    }
}

final class CsvExporter extends AbstractReportExporter
{
    public function supports(string $format): bool
    {
        return $format === 'csv';
    }

    protected function getContentType(): string
    {
        return 'text/csv';
    }

    protected function getFileExtension(): string
    {
        return 'csv';
    }

    protected function writeHeader(): void
    {
        $handle = fopen('php://output', 'w');
        foreach ($this->getColumnHeaders() as $headers) {
            fputcsv($handle, $headers);
        }
        fclose($handle);
    }

    protected function writeItem(Report $report, $item): void
    {
        $handle = fopen('php://output', 'w');
        fputcsv($handle, $this->buildRow($report, $item));
        fclose($handle);
    }
}

final class ExportOrchestrator
{
    /** @var ExporterInterface[] */
    private array $exporters = [];

    public function registerExporter(ExporterInterface $exporter): void
    {
        $this->exporters[] = $exporter;
    }

    public function export(int $reportId, string $format): StreamedResponse
    {
        foreach ($this->exporters as $exporter) {
            if ($exporter->supports($format)) {
                return $exporter->exportToStream($reportId);
            }
        }

        throw new \RuntimeException("No exporter found for format: {$format}");
    }
}
