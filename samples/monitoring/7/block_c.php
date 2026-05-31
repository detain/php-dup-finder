<?php

declare(strict_types=1);

namespace App\DataProcessing\Stage;

class DataAggregationStage
{
    private MetricsCollector $metrics;
    private LoggerInterface $logger;
    private AlertDispatcher $alerts;

    public function __construct(
        MetricsCollector $metrics,
        LoggerInterface $logger,
        AlertDispatcher $alerts
    ) {
        $this->metrics = $metrics;
        $this->logger = $logger;
        $this->alerts = $alerts;
    }

    public function aggregate(ValidatedBatch $batch): AggregatedResult
    {
        $startTime = microtime(true);
        $stageId = uniqid('aggregate_', true);

        $this->recordAggregationStarted($stageId, count($batch->getValidRecords()));

        try {
            $records = $batch->getValidRecords();

            $aggregated = $this->computeAggregations($records);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordAggregationCompleted(
                $stageId,
                count($records),
                $aggregated,
                $duration
            );

            return $aggregated;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordAggregationFailed($stageId, $e, $duration);

            throw $e;
        }
    }

    private function recordAggregationStarted(string $stageId, int $recordCount): void
    {
        $labels = [
            'stage' => 'data_aggregation',
            'stage_id' => $stageId
        ];

        $this->metrics->incrementCounter(
            'pipeline_stage_started_total',
            'Total pipeline stages started',
            1,
            $labels
        );

        $this->metrics->gauge(
            'pipeline_stage_records_in_progress',
            'Records being processed by stage',
            $recordCount,
            $labels
        );

        $this->logger->debug('Aggregation stage started', [
            'stage_id' => $stageId,
            'record_count' => $recordCount
        ]);
    }

    private function recordAggregationCompleted(
        string $stageId,
        int $recordCount,
        AggregatedResult $result,
        float $durationMs
    ): void {
        $labels = [
            'stage' => 'data_aggregation',
            'stage_id' => $stageId
        ];

        $this->metrics->incrementCounter(
            'pipeline_stage_completed_total',
            'Total pipeline stages completed',
            1,
            $labels
        );

        $this->metrics->histogram(
            'pipeline_stage_duration_milliseconds',
            'Pipeline stage duration in milliseconds',
            $durationMs,
            $labels
        );

        $this->metrics->gauge(
            'pipeline_stage_records_in_progress',
            'Records being processed by stage',
            0,
            $labels
        );

        $this->metrics->histogram(
            'pipeline_aggregation_result_count',
            'Number of aggregated results',
            (float)count($result->getGroups()),
            $labels
        );

        $this->logger->info('Aggregation stage completed', [
            'stage_id' => $stageId,
            'record_count' => $recordCount,
            'group_count' => count($result->getGroups()),
            'duration_ms' => round($durationMs, 2)
        ]);
    }

    private function recordAggregationFailed(string $stageId, \Exception $error, float $durationMs): void
    {
        $labels = [
            'stage' => 'data_aggregation',
            'stage_id' => $stageId,
            'error_type' => get_class($error)
        ];

        $this->metrics->incrementCounter(
            'pipeline_stage_failed_total',
            'Total pipeline stage failures',
            1,
            $labels
        );

        $this->metrics->histogram(
            'pipeline_stage_error_duration_milliseconds',
            'Pipeline stage error duration in milliseconds',
            $durationMs,
            $labels
        );

        $this->logger->error('Aggregation stage failed', [
            'stage_id' => $stageId,
            'error' => $error->getMessage(),
            'duration_ms' => round($durationMs, 2)
        ]);

        $this->alerts->sendFailureAlert(
            'Pipeline',
            'data_aggregation',
            $error->getMessage(),
            ['stage_id' => $stageId]
        );
    }

    private function computeAggregations(array $records): AggregatedResult
    {
        $groups = [];

        foreach ($records as $record) {
            $key = $record['category'] ?? 'unknown';

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'records' => [],
                    'sum' => 0,
                    'count' => 0
                ];
            }

            $groups[$key]['records'][] = $record;
            $groups[$key]['sum'] += $record['value'] ?? 0;
            $groups[$key]['count']++;
        }

        return new AggregatedResult($groups);
    }
}
