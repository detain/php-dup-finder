<?php

declare(strict_types=1);

namespace App\Formatting\Json;

use App\Entity\Report;
use App\Repository\ReportRepository;
use App\Service\JsonSerializer;
use Psr\Log\LoggerInterface;

final class ReportJsonFormatter
{
    public function __construct(
        private readonly ReportRepository $reportRepository,
        private readonly JsonSerializer $jsonSerializer,
        private readonly LoggerInterface $logger,
    ) {}

    public function formatReport(int $reportId): string
    {
        $report = $this->reportRepository->findById($reportId);

        if ($report === null) {
            throw new \RuntimeException("Report {$reportId} not found");
        }

        $this->logger->info('Formatting report to JSON', [
            'report_id' => $reportId,
            'format' => 'json',
        ]);

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

        $json = $this->jsonSerializer->serialize($data, JSON_PRETTY_PRINT);

        $this->logger->info('Report formatted to JSON successfully', [
            'report_id' => $reportId,
            'size_bytes' => strlen($json),
        ]);

        return $json;
    }

    public function formatReportSummary(int $reportId): string
    {
        $report = $this->reportRepository->findById($reportId);

        if ($report === null) {
            throw new \RuntimeException("Report {$reportId} not found");
        }

        $data = [
            'id' => $report->getId(),
            'title' => $report->getTitle(),
            'item_count' => count($report->getLineItems()),
            'total_amount' => $this->calculateTotal($report),
            'generated_at' => (new \DateTimeImmutable())->format('c'),
        ];

        return $this->jsonSerializer->serialize($data, JSON_PRETTY_PRINT);
    }

    private function calculateTotal(Report $report): float
    {
        return array_reduce(
            $report->getLineItems(),
            fn(float $carry, $item) => $carry + $item->getAmount(),
            0.0
        );
    }
}
