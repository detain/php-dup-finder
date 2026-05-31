<?php

declare(strict_types=1);

namespace App\Reporting\Pdf;

use App\Entity\MarketingReport;
use App\Repository\MarketingReportRepository;
use App\Service\PdfBuilder;
use App\Service\ChartRenderer;
use Psr\Log\LoggerInterface;
use setasign\Fpdi\Tcpdf\Fpdi;

final class CampaignPerformancePdfGenerator
{
    public function __construct(
        private readonly MarketingReportRepository $reports,
        private readonly PdfBuilder $pdfBuilder,
        private readonly ChartRenderer $chartRenderer,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateCampaignReport(int $reportId): string
    {
        $report = $this->reports->findById($reportId);

        if ($report === null) {
            $this->logger->error('Campaign performance report not found', [
                'report_id' => $reportId,
            ]);
            throw new \RuntimeException("Report {$reportId} not found");
        }

        $pdf = new Fpdi();
        $pdf->SetMargins(15, 20, 15);
        $pdf->SetAutoPageBreak(true, 25);

        $this->addTitlePage($pdf, $report);
        $this->addExecutiveSummary($pdf, $report);
        $this->addCampaignHighlights($pdf, $report);
        $this->addChannelBreakdown($pdf, $report);
        $this->addAudienceAnalysis($pdf, $report);
        $this->addConversionMetrics($pdf, $report);
        $this->addROIAnalysis($pdf, $report);
        $this->addNotesAndDisclosures($pdf, $report);
        $this->addAppendix($pdf, $report);

        $filename = sprintf(
            'campaign_report_%s_%d.pdf',
            $report->getCampaignName(),
            $report->getId()
        );
        $path = '/var/storage/reports/campaigns/' . $filename;
        $pdf->Output('F', $path);

        $this->logger->info('Campaign performance report PDF generated', [
            'report_id' => $reportId,
            'path' => $path,
        ]);

        return $path;
    }

    private function addTitlePage(Fpdi $pdf, MarketingReport $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 28);
        $pdf->Cell(0, 20, 'Campaign Performance Report', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 16);
        $pdf->Cell(0, 10, $report->getCompanyName(), 0, 1, 'C');
        $pdf->Cell(0, 8, $report->getCampaignName(), 0, 1, 'C');
        $pdf->Ln(20);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 6, 'Prepared: ' . $report->getPreparedAt()->format('F j, Y'), 0, 1, 'C');
        $pdf->Cell(0, 6, 'Report ID: ' . $report->getReportNumber(), 0, 1, 'C');
    }

    private function addExecutiveSummary(Fpdi $pdf, MarketingReport $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'Executive Summary', 0, 1, 'L');
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', '', 11);

        $summary = $report->getExecutiveSummary();
        $pdf->MultiCell(0, 6, $summary, 0, 'J');

        $pdf->Ln(10);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Key Highlights', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 11);
        foreach ($report->getKeyHighlights() as $highlight) {
            $pdf->Cell(5, 6, '-', 0, 0, 'L');
            $pdf->MultiCell(0, 6, $highlight, 0, 'J');
        }
    }

    private function addCampaignHighlights(Fpdi $pdf, MarketingReport $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'Campaign Highlights', 0, 1, 'L');
        $pdf->Ln(5);

        $highlights = [
            'Total Impressions' => number_format($report->getTotalImpressions()),
            'Total Clicks' => number_format($report->getTotalClicks()),
            'Click-Through Rate' => $report->getCtr() . '%',
            'Total Conversions' => number_format($report->getTotalConversions()),
        ];

        foreach ($highlights as $label => $value) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(80, 8, $label . ':', 0, 0, 'L');
            $pdf->SetFont('helvetica', '', 11);
            $pdf->Cell(0, 8, $value, 0, 1, 'L');
        }
    }

    private function addChannelBreakdown(Fpdi $pdf, MarketingReport $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'Channel Breakdown', 0, 1, 'L');
        $pdf->Ln(5);

        $chartPath = $this->chartRenderer->renderPieChart(
            $report->getPerformanceByChannel(),
            ['width' => 150, 'height' => 150]
        );
        $pdf->Image($chartPath, 80, 50, 50);

        $pdf->Ln(60);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Performance by Channel', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 11);
        foreach ($report->getPerformanceByChannel() as $channel => $metrics) {
            $pdf->Cell(0, 6, "{$channel}: " . number_format($metrics['impressions']) . " impressions", 0, 1, 'L');
        }
    }

    private function addAudienceAnalysis(Fpdi $pdf, MarketingReport $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'Audience Analysis', 0, 1, 'L');
        $pdf->Ln(5);

        $chartPath = $this->chartRenderer->renderBarChart(
            $report->getDemographicBreakdown(),
            ['width' => 170, 'height' => 80]
        );
        $pdf->Image($chartPath, 20, 50, 170);

        $pdf->Ln(85);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Demographic Breakdown', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 11);
        foreach ($report->getDemographicBreakdown() as $demo => $percentage) {
            $pdf->Cell(0, 6, "{$demo}: {$percentage}%", 0, 1, 'L');
        }
    }

    private function addConversionMetrics(Fpdi $pdf, MarketingReport $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'Conversion Metrics', 0, 1, 'L');
        $pdf->Ln(5);

        $this->renderTable($pdf, $report->getConversionData(), [80, 40, 40]);
    }

    private function addROIAnalysis(Fpdi $pdf, MarketingReport $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'ROI Analysis', 0, 1, 'L');
        $pdf->Ln(5);

        $this->renderTable($pdf, $report->getRoiData(), [80, 40, 40]);
    }

    private function addNotesAndDisclosures(Fpdi $pdf, MarketingReport $report): void
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

    private function addAppendix(Fpdi $pdf, MarketingReport $report): void
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

    private function renderTable(Fpdi $pdf, array $data, array $widths): void
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
