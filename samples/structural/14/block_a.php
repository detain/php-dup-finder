<?php
declare(strict_types=1);

namespace Reporting\Generation;

use Psr\Log\LoggerInterface;

final class MonthlyReportGenerator
{
    private const REPORT_TYPE = 'monthly';
    private const MAX_DATA_POINTS = 10000;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ReportQueryFactory $queryFactory,
        private readonly ReportRenderer $renderer,
        private readonly ReportStorage $storage,
        private readonly ReportAnalytics $analytics,
    ) {}

    public function generate(ReportPeriod $period, string $outputFormat): GeneratedReport
    {
        $this->logger->info('Generating monthly report', [
            'period' => $period,
            'format' => $outputFormat,
        ]);

        $startTime = microtime(true);

        $rawData = $this->fetchData($period);
        $this->logger->debug('Data fetched', ['records' => count($rawData)]);

        $aggregatedData = $this->aggregate($rawData);
        $this->logger->debug('Data aggregated', ['aggregations' => count($aggregatedData)]);

        $enrichedData = $this->enrich($aggregatedData);
        $this->logger->debug('Data enriched', ['enriched' => count($enrichedData)]);

        $reportContent = $this->render($enrichedData, $period, $outputFormat);
        $this->logger->debug('Report rendered');

        $reportId = $this->save($reportContent, $period, $outputFormat);
        $this->logger->info('Report saved', ['report_id' => $reportId]);

        $this->analytics->trackReportGeneration($reportId, $period);

        $elapsedTime = microtime(true) - $startTime;

        return new GeneratedReport(
            id: $reportId,
            type: self::REPORT_TYPE,
            period: $period,
            format: $outputFormat,
            sizeBytes: strlen($reportContent),
            generationTimeSeconds: round($elapsedTime, 2),
            recordCount: count($rawData),
        );
    }

    private function fetchData(ReportPeriod $period): array
    {
        $query = $this->queryFactory->createMonthlyReportQuery($period);

        $query->setMaxResults(self::MAX_DATA_POINTS);

        return $query->getResult();
    }

    private function aggregate(array $rawData): array
    {
        $aggregated = [];

        foreach ($rawData as $record) {
            $key = $this->getAggregationKey($record);

            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'count' => 0,
                    'total_amount' => 0.0,
                    'avg_amount' => 0.0,
                ];
            }

            $aggregated[$key]['count']++;
            $aggregated[$key]['total_amount'] += $record->getAmount();
        }

        foreach ($aggregated as $key => $data) {
            $aggregated[$key]['avg_amount'] = $data['total_amount'] / max(1, $data['count']);
        }

        return $aggregated;
    }

    private function enrich(array $aggregatedData): array
    {
        $enriched = [];

        foreach ($aggregatedData as $key => $data) {
            $enriched[$key] = [
                'segment' => $this->classifySegment($data),
                'trend' => $this->calculateTrend($key, $data),
                'anomaly_score' => $this->detectAnomaly($data),
                'benchmark_comparison' => $this->compareToBenchmark($data),
                'raw' => $data,
            ];
        }

        return $enriched;
    }

    private function render(array $enrichedData, ReportPeriod $period, string $format): string
    {
        $templateData = [
            'period' => $period,
            'generated_at' => new \DateTimeImmutable(),
            'data' => $enrichedData,
            'summary' => $this->generateSummary($enrichedData),
        ];

        return $this->renderer->render($templateData, $format);
    }

    private function save(string $content, ReportPeriod $period, string $format): string
    {
        $filename = sprintf(
            'monthly_report_%s_%s.%s',
            $period->getYear(),
            $period->getMonth(),
            $this->getFileExtension($format)
        );

        return $this->storage->save($filename, $content);
    }

    private function getAggregationKey(mixed $record): string
    {
        return $record->getCategory() . '|' . $record->getRegion();
    }

    private function classifySegment(array $data): string
    {
        if ($data['total_amount'] > 1000000) {
            return 'enterprise';
        }

        if ($data['total_amount'] > 100000) {
            return 'mid_market';
        }

        return 'smb';
    }

    private function calculateTrend(string $key, array $data): string
    {
        return 'stable';
    }

    private function detectAnomaly(array $data): float
    {
        return 0.0;
    }

    private function compareToBenchmark(array $data): array
    {
        return ['index' => 100, 'delta' => 0];
    }

    private function generateSummary(array $enrichedData): array
    {
        $totalCount = 0;
        $totalAmount = 0.0;

        foreach ($enrichedData as $data) {
            $totalCount += $data['raw']['count'];
            $totalAmount += $data['raw']['total_amount'];
        }

        return [
            'total_records' => $totalCount,
            'total_amount' => $totalAmount,
            'segment_count' => count($enrichedData),
        ];
    }

    private function getFileExtension(string $format): string
    {
        return match ($format) {
            'pdf' => 'pdf',
            'csv' => 'csv',
            'excel' => 'xlsx',
            default => 'html',
        };
    }
}
