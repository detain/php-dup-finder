<?php

declare(strict_types=1);

namespace App\Formatting;

use App\Entity\Report;
use App\Repository\ReportRepository;
use Psr\Log\LoggerInterface;

interface ReportFormatterInterface
{
    public function format(Report $report): string;
    public function formatSummary(Report $report): string;
    public function getFormatName(): string;
}

abstract class AbstractReportFormatter implements ReportFormatterInterface
{
    public function __construct(
        protected readonly ReportRepository $reportRepository,
        protected readonly LoggerInterface $logger,
    ) {}

    public function format(int $reportId): string
    {
        $report = $this->reportRepository->findById($reportId);

        if ($report === null) {
            throw new \RuntimeException("Report {$reportId} not found");
        }

        $this->logger->info('Formatting report', [
            'report_id' => $reportId,
            'format' => $this->getFormatName(),
        ]);

        return $this->formatReport($report);
    }

    public function formatSummary(int $reportId): string
    {
        $report = $this->reportRepository->findById($reportId);

        if ($report === null) {
            throw new \RuntimeException("Report {$reportId} not found");
        }

        return $this->formatReportSummary($report);
    }

    protected function buildReportData(Report $report): array
    {
        $data = [
            'meta' => [
                'id' => $report->getId(),
                'title' => $report->getTitle(),
                'created_at' => $report->getCreatedAt()->format('c'),
                'status' => $report->getStatus(),
            ],
            'data' => [],
        ];

        foreach ($report->getLineItems() as $lineItem) {
            $data['data'][] = [
                'id' => $lineItem->getId(),
                'description' => $lineItem->getDescription(),
                'amount' => $lineItem->getAmount(),
                'category' => $lineItem->getCategory(),
                'date' => $lineItem->getDate()->format('Y-m-d'),
            ];
        }

        return $data;
    }

    protected function calculateTotal(Report $report): float
    {
        return array_reduce(
            $report->getLineItems(),
            fn(float $carry, $item) => $carry + $item->getAmount(),
            0.0
        );
    }
}
