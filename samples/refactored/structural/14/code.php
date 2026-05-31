<?php
declare(strict_types=1);

namespace Reporting\Shared;

abstract class BaseReportGenerator
{
    protected LoggerInterface $logger;
    protected ReportQueryFactory $queryFactory;
    protected ReportRenderer $renderer;
    protected ReportStorage $storage;
    protected ReportAnalytics $analytics;

    protected const MAX_DATA_POINTS = 10000;

    public function generate(ReportPeriod $period, string $outputFormat): GeneratedReport
    {
        $startTime = microtime(true);

        $rawData = $this->fetchData($period);
        $aggregatedData = $this->aggregate($rawData);
        $enrichedData = $this->enrich($aggregatedData);
        $reportContent = $this->render($enrichedData, $period, $outputFormat);
        $reportId = $this->save($reportContent, $period, $outputFormat);

        $this->analytics->trackReportGeneration($reportId, $period);

        return new GeneratedReport(
            id: $reportId,
            type: $this->getReportType(),
            period: $period,
            format: $outputFormat,
            sizeBytes: strlen($reportContent),
            generationTimeSeconds: round(microtime(true) - $startTime, 2),
            recordCount: count($rawData),
        );
    }

    protected function aggregate(array $rawData): array
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

    protected function enrich(array $aggregatedData): array
    {
        $enriched = [];

        foreach ($aggregatedData as $key => $data) {
            $enriched[$key] = [
                'segment' => $this->classifySegment($data),
                'trend' => 'stable',
                'anomaly_score' => 0.0,
                'benchmark_comparison' => ['index' => 100, 'delta' => 0],
                'raw' => $data,
            ];
        }

        return $enriched;
    }

    protected function generateSummary(array $enrichedData): array
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

    abstract protected function getReportType(): string;
    abstract protected function fetchData(ReportPeriod $period): array;
    abstract protected function getAggregationKey(mixed $record): string;
    abstract protected function classifySegment(array $data): string;
    abstract protected function render(array $enrichedData, ReportPeriod $period, string $format): string;
    abstract protected function save(string $content, ReportPeriod $period, string $format): string;
}
