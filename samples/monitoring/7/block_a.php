<?php

declare(strict_types=1);

namespace App\DataProcessing\Stage;

class DataIngestionStage
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

    public function process(DataBatch $batch): ProcessedBatch
    {
        $startTime = microtime(true);
        $stageId = uniqid('ingest_', true);

        $this->recordStageStarted('data_ingestion', $stageId, count($batch->getRecords()));

        try {
            $records = $batch->getRecords();
            $processedRecords = [];

            foreach ($records as $record) {
                $processedRecords[] = $this->transformRecord($record);
            }

            $processedBatch = new ProcessedBatch($processedRecords);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordStageCompleted(
                'data_ingestion',
                $stageId,
                count($records),
                count($processedRecords),
                $duration
            );

            return $processedBatch;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordStageFailed(
                'data_ingestion',
                $stageId,
                $e,
                $duration
            );

            throw $e;
        }
    }

    private function recordStageStarted(string $stageName, string $stageId, int $recordCount): void
    {
        $labels = [
            'stage' => $stageName,
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

        $this->logger->debug('Pipeline stage started', [
            'stage' => $stageName,
            'stage_id' => $stageId,
            'record_count' => $recordCount
        ]);
    }

    private function recordStageCompleted(
        string $stageName,
        string $stageId,
        int $inputCount,
        int $outputCount,
        float $durationMs
    ): void {
        $labels = [
            'stage' => $stageName,
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

        $throughput = $inputCount > 0 ? ($outputCount / $durationMs) * 1000 : 0;

        $this->metrics->gauge(
            'pipeline_stage_throughput_records_per_second',
            'Stage throughput in records per second',
            $throughput,
            $labels
        );

        $this->logger->info('Pipeline stage completed', [
            'stage' => $stageName,
            'stage_id' => $stageId,
            'input_count' => $inputCount,
            'output_count' => $outputCount,
            'duration_ms' => round($durationMs, 2),
            'throughput' => round($throughput, 2)
        ]);
    }

    private function recordStageFailed(
        string $stageName,
        string $stageId,
        \Exception $error,
        float $durationMs
    ): void {
        $labels = [
            'stage' => $stageName,
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

        $this->metrics->gauge(
            'pipeline_stage_records_in_progress',
            'Records being processed by stage',
            0,
            ['stage' => $stageName, 'stage_id' => $stageId]
        );

        $this->logger->error('Pipeline stage failed', [
            'stage' => $stageName,
            'stage_id' => $stageId,
            'error' => $error->getMessage(),
            'error_type' => get_class($error),
            'duration_ms' => round($durationMs, 2)
        ]);

        $this->alerts->sendFailureAlert(
            'Pipeline',
            $stageName,
            $error->getMessage(),
            ['stage_id' => $stageId]
        );
    }

    private function transformRecord(array $record): array
    {
        if (!isset($record['timestamp'])) {
            $record['timestamp'] = time();
        }

        if (!isset($record['id'])) {
            $record['id'] = bin2hex(random_bytes(16));
        }

        return $record;
    }
}
