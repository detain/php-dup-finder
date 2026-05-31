<?php

declare(strict_types=1);

namespace App\DataProcessing\Stage;

class DataValidationStage
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

    public function validate(ProcessedBatch $batch): ValidatedBatch
    {
        $startTime = microtime(true);
        $stageId = uniqid('validate_', true);

        $this->recordValidationStarted($stageId, count($batch->getRecords()));

        try {
            $records = $batch->getRecords();
            $validRecords = [];
            $invalidRecords = [];

            foreach ($records as $record) {
                if ($this->isValid($record)) {
                    $validRecords[] = $record;
                } else {
                    $invalidRecords[] = $record;
                }
            }

            $validatedBatch = new ValidatedBatch($validRecords, $invalidRecords);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordValidationCompleted(
                $stageId,
                count($records),
                count($validRecords),
                count($invalidRecords),
                $duration
            );

            return $validatedBatch;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordValidationFailed($stageId, $e, $duration);

            throw $e;
        }
    }

    private function recordValidationStarted(string $stageId, int $recordCount): void
    {
        $labels = [
            'stage' => 'data_validation',
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

        $this->logger->debug('Validation stage started', [
            'stage_id' => $stageId,
            'record_count' => $recordCount
        ]);
    }

    private function recordValidationCompleted(
        string $stageId,
        int $totalCount,
        int $validCount,
        int $invalidCount,
        float $durationMs
    ): void {
        $labels = [
            'stage' => 'data_validation',
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

        $validityRate = $totalCount > 0 ? ($validCount / $totalCount) * 100 : 0;

        $this->metrics->gauge(
            'pipeline_validation_validity_rate_percent',
            'Percentage of valid records',
            $validityRate,
            $labels
        );

        $this->metrics->counter(
            'pipeline_validation_invalid_records_total',
            'Total invalid records',
            $invalidCount,
            $labels
        );

        $this->logger->info('Validation stage completed', [
            'stage_id' => $stageId,
            'total_count' => $totalCount,
            'valid_count' => $validCount,
            'invalid_count' => $invalidCount,
            'validity_rate' => round($validityRate, 2),
            'duration_ms' => round($durationMs, 2)
        ]);
    }

    private function recordValidationFailed(string $stageId, \Exception $error, float $durationMs): void
    {
        $labels = [
            'stage' => 'data_validation',
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

        $this->logger->error('Validation stage failed', [
            'stage' => 'data_validation',
            'stage_id' => $stageId,
            'error' => $error->getMessage(),
            'duration_ms' => round($durationMs, 2)
        ]);

        $this->alerts->sendFailureAlert(
            'Pipeline',
            'data_validation',
            $error->getMessage(),
            ['stage_id' => $stageId]
        );
    }

    private function isValid(array $record): bool
    {
        if (!isset($record['id']) || empty($record['id'])) {
            return false;
        }

        if (!isset($record['timestamp']) || !is_numeric($record['timestamp'])) {
            return false;
        }

        return true;
    }
}
