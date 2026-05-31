<?php

declare(strict_types=1);

namespace App\DataProcessing\Pipeline;

trait PipelineStageMetricsTrait
{
    private MetricsCollector $metrics;
    private LoggerInterface $logger;
    private AlertDispatcher $alerts;

    protected function recordStageStarted(string $stageName, string $stageId, int $recordCount): void
    {
        $labels = ['stage' => $stageName, 'stage_id' => $stageId];

        $this->metrics->incrementCounter('pipeline_stage_started_total', 1, $labels);
        $this->metrics->gauge('pipeline_stage_records_in_progress', $recordCount, $labels);
        $this->logger->debug("{$stageName} stage started", ['stage_id' => $stageId, 'record_count' => $recordCount]);
    }

    protected function recordStageCompleted(
        string $stageName,
        string $stageId,
        int $inputCount,
        int $outputCount,
        float $durationMs,
        array $additionalMetrics = []
    ): void {
        $labels = ['stage' => $stageName, 'stage_id' => $stageId];

        $this->metrics->incrementCounter('pipeline_stage_completed_total', 1, $labels);
        $this->metrics->histogram('pipeline_stage_duration_milliseconds', $durationMs, $labels);
        $this->metrics->gauge('pipeline_stage_records_in_progress', 0, $labels);

        foreach ($additionalMetrics as $name => $value) {
            $this->metrics->gauge($name, $value, $labels);
        }

        $throughput = $inputCount > 0 ? ($outputCount / $durationMs) * 1000 : 0;

        $this->logger->info("{$stageName} stage completed", [
            'stage_id' => $stageId,
            'input_count' => $inputCount,
            'output_count' => $outputCount,
            'duration_ms' => round($durationMs, 2),
            'throughput' => round($throughput, 2)
        ]);
    }

    protected function recordStageFailed(
        string $stageName,
        string $stageId,
        \Exception $error,
        float $durationMs
    ): void {
        $labels = ['stage' => $stageName, 'stage_id' => $stageId, 'error_type' => get_class($error)];

        $this->metrics->incrementCounter('pipeline_stage_failed_total', 1, $labels);
        $this->metrics->histogram('pipeline_stage_error_duration_milliseconds', $durationMs, $labels);
        $this->metrics->gauge('pipeline_stage_records_in_progress', 0, ['stage' => $stageName, 'stage_id' => $stageId]);

        $this->logger->error("{$stageName} stage failed", [
            'stage' => $stageName,
            'stage_id' => $stageId,
            'error' => $error->getMessage()
        ]);

        $this->alerts->sendFailureAlert('Pipeline', $stageName, $error->getMessage(), ['stage_id' => $stageId]);
    }
}

abstract class AbstractPipelineStage
{
    use PipelineStageMetricsTrait;

    abstract protected function executeStage(mixed $input): mixed;
    abstract protected function getStageName(): string;

    public function process(mixed $input): mixed
    {
        $startTime = microtime(true);
        $stageId = uniqid($this->getStageName() . '_', true);

        $this->recordStageStarted($this->getStageName(), $stageId, $this->getRecordCount($input));

        try {
            $result = $this->executeStage($input);
            $duration = (microtime(true) - $startTime) * 1000;

            $this->recordStageCompleted(
                $this->getStageName(),
                $stageId,
                $this->getRecordCount($input),
                $this->getResultCount($result),
                $duration,
                $this->getAdditionalMetrics($result)
            );

            return $result;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            $this->recordStageFailed($this->getStageName(), $stageId, $e, $duration);
            throw $e;
        }
    }

    protected function getRecordCount(mixed $input): int
    {
        return is_countable($input) ? count($input) : 0;
    }

    protected function getResultCount(mixed $result): int
    {
        return is_countable($result) ? count($result) : 0;
    }

    protected function getAdditionalMetrics(mixed $result): array
    {
        return [];
    }
}
