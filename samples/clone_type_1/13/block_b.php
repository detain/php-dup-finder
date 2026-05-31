<?php

declare(strict_types=1);

namespace App\Reporting\Pdf;

use App\Entity\SalesReport;
use App\Repository\SalesReportRepository;
use App\Service\PdfBuilder;
use App\Service\ChartRenderer;
use Psr\Log\LoggerInterface;
use setasign\Fpdi\Tcpdf\Fpdi;

final class QuarterlySalesPdfGenerator
{
    public function __construct(
        private readonly SalesReportRepository $reports,
        private readonly PdfBuilder $pdfBuilder,
        private readonly ChartRenderer $chartRenderer,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateQuarterlyReport(int $reportId): string
    {
        $report = $this->reports->findById($reportId);

        if ($report === null) {
            $this->logger->error('Quarterly sales report not found', [
                'report_id' => $reportId,
            ]);
            throw new \RuntimeException("Report {$reportId} not found");
        }

        $pdf = new Fpdi();
        $pdf->SetMargins(15, 20, 15);
        $pdf->SetAutoPageBreak(true, 25);

        $this->addTitlePage($pdf, $report);
        $this->addExecutiveSummary($pdf, $report);
        $this->addSalesHighlights($pdf, $report);
        $this->addRevenueBreakdown($pdf, $report);
        $this->addRegionalAnalysis($pdf, $report);
        $this->addProductPerformance($pdf, $report);
        $this->addCustomerInsights($pdf, $report);
        $this->addNotesAndDisclosures($pdf, $report);
        $this->addAppendix($pdf, $report);

        $filename = sprintf(
            'quarterly_sales_report_%s_Q%d.pdf',
            $report->getQuarter(),
            $report->getId()
        );
        $path = '/var/storage/reports/quarterly/' . $filename;
        $pdf->Output('F', $path);

        $this->logger->info('Quarterly sales report PDF generated', [
            'report_id' => $reportId,
            'path' => $path,
        ]);

        return $path;
    }

    private function addTitlePage(Fpdi $pdf, SalesReport $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 28);
        $pdf->Cell(0, 20, 'Quarterly Sales Report', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 16);
        $pdf->Cell(0, 10, $report->getCompanyName(), 0, 1, 'C');
        $pdf->Cell(0, 8, 'Q' . $report->getQuarter() . ' ' . $report->getYear(), 0, 1, 'C');
        $pdf->Ln(20);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 6, 'Prepared: ' . $report->getPreparedAt()->format('F j, Y'), 0, 1, 'C');
        $pdf->Cell(0, 6, 'Report ID: ' . $report->getReportNumber(), 0, 1, 'C');
    }

    private function addExecutiveSummary(Fpdi $pdf, SalesReport $report): void
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

    private function addSalesHighlights(Fpdi $pdf, SalesReport $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'Sales Highlights', 0, 1, 'L');
        $pdf->Ln(5);

        $highlights = [
            'Total Sales' => '$' . number_format($report->getTotalSales(), 2),
            'Units Sold' => number_format($report->getUnitsSold()),
            'Average Order Value' => '$' . number_format($report->getAverageOrderValue(), 2),
            'Quarter-over-Quarter Growth' => $report->getQuarterOverQuarterGrowth() . '%',
        ];

        foreach ($highlights as $label => $value) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(80, 8, $label . ':', 0, 0, 'L');
            $pdf->SetFont('helvetica', '', 11);
            $pdf->Cell(0, 8, $value, 0, 1, 'L');
        }
    }

    private function addRevenueBreakdown(Fpdi $pdf, SalesReport $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'Revenue Breakdown', 0, 1, 'L');
        $pdf->Ln(5);

        $chartPath = $this->chartRenderer->renderPieChart(
            $report->getRevenueByCategory(),
            ['width' => 150, 'height' => 150]
        );
        $pdf->Image($chartPath, 80, 50, 50);

        $pdf->Ln(60);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Revenue by Category', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 11);
        foreach ($report->getRevenueByCategory() as $category => $amount) {
            $pdf->Cell(0, 6, "{$category}: \$" . number_format($amount, 2), 0, 1, 'L');
        }
    }

    private function addRegionalAnalysis(Fpdi $pdf, SalesReport $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'Regional Analysis', 0, 1, 'L');
        $pdf->Ln(5);

        $chartPath = $this->chartRenderer->renderBarChart(
            $report->getSalesByRegion(),
            ['width' => 170, 'height' => 80]
        );
        $pdf->Image($chartPath, 20, 50, 170);

        $pdf->Ln(85);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Sales by Region', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 11);
        foreach ($report->getSalesByRegion() as $region => $amount) {
            $pdf->Cell(0, 6, "{$region}: \$" . number_format($amount, 2), 0, 1, 'L');
        }
    }

    private function addProductPerformance(Fpdi $pdf, SalesReport $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'Product Performance', 0, 1, 'L');
        $pdf->Ln(5);

        $this->renderTable($pdf, $report->getProductPerformanceData(), [80, 40, 40]);
    }

    private function addCustomerInsights(Fpdi $pdf, SalesReport $report): void
    {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'Customer Insights', 0, 1, 'L');
        $pdf->Ln(5);

        $this->renderTable($pdf, $report->getCustomerInsightsData(), [80, 40, 40]);
    }

    private function addNotesAndDisclosures(Fpdi $pdf, SalesReport $report): void
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

    private function addAppendix(Fpdi $pdf, SalesReport $report): void
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
