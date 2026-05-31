<?php

declare(strict_types=1);

namespace App\Formatting\Xml;

use App\Entity\Report;
use App\Repository\ReportRepository;
use App\Service\XmlSerializer;
use Psr\Log\LoggerInterface;

final class ReportXmlFormatter
{
    public function __construct(
        private readonly ReportRepository $reportRepository,
        private readonly XmlSerializer $xmlSerializer,
        private readonly LoggerInterface $logger,
    ) {}

    public function formatReport(int $reportId): string
    {
        $report = $this->reportRepository->findById($reportId);

        if ($report === null) {
            throw new \RuntimeException("Report {$reportId} not found");
        }

        $this->logger->info('Formatting report to XML', [
            'report_id' => $reportId,
            'format' => 'xml',
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

        $xml = $this->xmlSerializer->serialize($data);

        $this->logger->info('Report formatted to XML successfully', [
            'report_id' => $reportId,
            'size_bytes' => strlen($xml),
        ]);

        return $xml;
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

        return $this->xmlSerializer->serialize($data);
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
