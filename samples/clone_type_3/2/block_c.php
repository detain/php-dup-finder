<?php

declare(strict_types=1);

namespace App\Export\Json;

use App\Entity\Report;
use App\Repository\ReportRepository;
use App\Service\JsonSerializer;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportJsonExporter
{
    public function __construct(
        private readonly ReportRepository $reportRepository,
        private readonly JsonSerializer $jsonSerializer,
        private readonly LoggerInterface $logger,
    ) {}

    public function exportToStream(int $reportId): StreamedResponse
    {
        $report = $this->reportRepository->findById($reportId);

        if ($report === null) {
            throw new \RuntimeException("Report {$reportId} not found");
        }

        $this->logger->info('Starting JSON export for report', [
            'report_id' => $reportId,
            'format' => 'json',
        ]);

        $response = new StreamedResponse(function () use ($report) {
            $data = [
                'meta' => [
                    'report_id' => $report->getId(),
                    'title' => $report->getTitle(),
                    'status' => $report->getStatus(),
                    'created_date' => $report->getCreatedAt()->format('Y-m-d'),
                    'exported_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
                'items' => [],
            ];

            foreach ($report->getLineItems() as $item) {
                $data['items'][] = [
                    'amount' => number_format($item->getAmount(), 2),
                    'department' => $item->getDepartment(),
                ];
            }

            echo $this->jsonSerializer->serialize($data, JSON_PRETTY_PRINT);
        });

        $filename = sprintf('report_%d_%s.json', $reportId, date('Ymd'));
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");

        $this->logger->info('JSON export completed', [
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

        $data = [
            'meta' => [
                'report_id' => $report->getId(),
                'title' => $report->getTitle(),
                'status' => $report->getStatus(),
                'created_date' => $report->getCreatedAt()->format('Y-m-d'),
                'exported_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            'items' => [],
        ];

        foreach ($report->getLineItems() as $item) {
            $data['items'][] = [
                'amount' => number_format($item->getAmount(), 2),
                'department' => $item->getDepartment(),
            ];
        }

        file_put_contents(
            $filePath,
            $this->jsonSerializer->serialize($data, JSON_PRETTY_PRINT)
        );

        $this->logger->info('JSON file exported', [
            'report_id' => $reportId,
            'file_path' => $filePath,
        ]);
    }
}
