<?php

declare(strict_types=1);

namespace App\Reporting\Pdf;

use App\Entity\Report;
use App\Repository\ReportRepository;
use App\Service\PdfBuilder;
use App\Service\ChartRenderer;
use Psr\Log\LoggerInterface;
use setasign\Fpdi\Tcpdf\Fpdi;

abstract class AbstractReportPdfGenerator
{
    public function __construct(
        protected readonly ReportRepository $reports,
        protected readonly PdfBuilder $pdfBuilder,
        protected readonly ChartRenderer $chartRenderer,
        protected readonly LoggerInterface $logger,
    ) {}

    public function generate(int $reportId): string
    {
        $report = $this->reports->findById($reportId);

        if ($report === null) {
            $this->logger->error($this->getReportType() . ' not found', [
                'report_id' => $reportId,
            ]);
            throw new \RuntimeException("Report {$reportId} not found");
        }

        $pdf = new Fpdi();
        $pdf->SetMargins(15, 20, 15);
        $pdf->SetAutoPageBreak(true, 25);

        $this->addTitlePage($pdf, $report);
        $this->addExecutiveSummary($pdf, $report);
        $this->addHighlights($pdf, $report);
        $this->addPrimaryBreakdown($pdf, $report);
        $this->addSecondaryAnalysis($pdf, $report);
        $this->addMetricsDetails($pdf, $report);
        $this->addNotesAndDisclosures($pdf, $report);
        $this->addAppendix($pdf, $report);

        $filename = $this->generateFilename($report);
        $path = '/var/storage/reports/' . $this->getReportType() . '/' . $filename;
        $pdf->Output('F', $path);

        $this->logger->info($this->getReportType() . ' PDF generated', [
            'report_id' => $reportId,
            'path' => $path,
        ]);

        return $path;
    }

    abstract protected function getReportType(): string;
    abstract protected function generateFilename(Report $report): string;
    abstract protected function getHighlightsData(Report $report): array;
    abstract protected function getPrimaryChartData(Report $report): array;
    abstract protected function getSecondaryChartData(Report $report): array;

    protected function addTitlePage(Fpdi $pdf, Report $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 28);
        $pdf->Cell(0, 20, $this->getReportTitle(), 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 16);
        $pdf->Cell(0, 10, $report->getCompanyName(), 0, 1, 'C');
        $pdf->Cell(0, 8, $this->getReportPeriod($report), 0, 1, 'C');
        $pdf->Ln(20);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 6, 'Prepared: ' . $report->getPreparedAt()->format('F j, Y'), 0, 1, 'C');
        $pdf->Cell(0, 6, 'Report ID: ' . $report->getReportNumber(), 0, 1, 'C');
    }

    protected function addExecutiveSummary(Fpdi $pdf, Report $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'Executive Summary', 0, 1, 'L');
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', '', 11);
        $pdf->MultiCell(0, 6, $report->getExecutiveSummary(), 0, 'J');

        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Key Highlights', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 11);
        foreach ($report->getKeyHighlights() as $highlight) {
            $pdf->Cell(5, 6, '-', 0, 0, 'L');
            $pdf->MultiCell(0, 6, $highlight, 0, 'J');
        }
    }

    protected function addHighlights(Fpdi $pdf, Report $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, $this->getHighlightsSectionTitle(), 0, 1, 'L');
        $pdf->Ln(5);

        foreach ($this->getHighlightsData($report) as $label => $value) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(80, 8, $label . ':', 0, 0, 'L');
            $pdf->SetFont('helvetica', '', 11);
            $pdf->Cell(0, 8, $value, 0, 1, 'L');
        }
    }

    protected function addPrimaryBreakdown(Fpdi $pdf, Report $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, $this->getPrimaryBreakdownTitle(), 0, 1, 'L');
        $pdf->Ln(5);

        $chartPath = $this->chartRenderer->renderPieChart(
            $this->getPrimaryChartData($report),
            ['width' => 150, 'height' => 150]
        );
        $pdf->Image($chartPath, 80, 50, 50);
    }

    protected function addSecondaryAnalysis(Fpdi $pdf, Report $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, $this->getSecondaryAnalysisTitle(), 0, 1, 'L');
        $pdf->Ln(5);

        $chartPath = $this->chartRenderer->renderBarChart(
            $this->getSecondaryChartData($report),
            ['width' => 170, 'height' => 80]
        );
        $pdf->Image($chartPath, 20, 50, 170);
    }

    protected function addMetricsDetails(Fpdi $pdf, Report $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, $this->getMetricsTitle(), 0, 1, 'L');
        $pdf->Ln(5);
        $this->renderTable($pdf, $this->getMetricsData($report), [80, 40, 40]);
    }

    protected function addNotesAndDisclosures(Fpdi $pdf, Report $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'Notes and Disclosures', 0, 1, 'L');
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', '', 11);

        foreach ($report->getNotes() as $note) {
            $pdf->MultiCell(0, 6, $note, 0, 'J');
            $pdf->Ln(3);
        }
    }

    protected function addAppendix(Fpdi $pdf, Report $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'Appendix', 0, 1, 'L');
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', '', 10);
        foreach ($report->getAppendixItems() as $title => $content) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 8, $title, 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);
            $pdf->MultiCell(0, 5, $content, 0, 'J');
            $pdf->Ln(5);
        }
    }

    protected function renderTable(Fpdi $pdf, array $data, array $widths): void
    {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);

        foreach ($data as $rowIndex => $row) {
            $fill = $rowIndex % 2 === 0;
            foreach ($row as $colIndex => $cell) {
                $pdf->Cell($widths[$colIndex] ?? 40, 7, (string)$cell, 1, 0, 'L', $fill);
            }
            $pdf->Ln();
        }
    }
}
