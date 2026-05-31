<?php

declare(strict_types=1);

namespace App\Jobs;

class BackgroundJobMetrics
{
    private StatsDClient $statsd;
    private LoggerInterface $logger;
    private array $jobLabels = [];

    public function __construct(StatsDClient $statsd, LoggerInterface $logger)
    {
        $this->statsd = $statsd;
        $this->logger = $logger;
    }

    public function recordJobQueued(
        string $jobName,
        string $queue,
        string $jobId,
        int $priority = 0
    ): void {
        $labels = [
            'job_name' => $jobName,
            'queue' => $queue,
            'priority' => (string)$priority
        ];

        $this->statsd->increment('jobs.queued.total', 1, $labels);

        $this->statsd->gauge('jobs.queued.in_flight', 1, $labels);

        $this->statsd->gauge('queues.depth', $this->getQueueDepth($queue), ['queue' => $queue]);

        $this->logger->info('Job queued', [
            'job_name' => $jobName,
            'queue' => $queue,
            'job_id' => $jobId,
            'priority' => $priority
        ]);
    }

    public function recordJobStarted(
        string $jobName,
        string $queue,
        string $jobId,
        int $attempt = 1
    ): void {
        $labels = [
            'job_name' => $jobName,
            'queue' => $queue,
            'attempt' => (string)$attempt
        ];

        $this->jobLabels = $labels;

        $this->statsd->increment('jobs.started.total', 1, $labels);

        $this->statsd->gauge('jobs.in_progress', 1, $labels);

        $this->statsd->timing('jobs.started_at', time(), $labels);

        $this->logger->info('Job started', [
            'job_name' => $jobName,
            'queue' => $queue,
            'job_id' => $jobId,
            'attempt' => $attempt
        ]);
    }

    public function recordJobCompleted(
        string $jobName,
        string $queue,
        string $jobId,
        float $durationMs,
        int $attempt,
        ?int $memoryUsedMb = null
    ): void {
        $labels = array_merge($this->jobLabels, [
            'result' => 'success'
        ]);

        $this->statsd->increment('jobs.completed.total', 1, $labels);

        $this->statsd->gauge('jobs.in_progress', -1, [
            'job_name' => $jobName,
            'queue' => $queue
        ]);

        $this->statsd->histogram('jobs.duration_seconds', $durationMs / 1000, $labels);

        $this->statsd->histogram('jobs.attempt_count', (float)$attempt, $labels);

        if ($memoryUsedMb !== null) {
            $this->statsd->gauge('jobs.memory_used_mb', $memoryUsedMb, $labels);
        }

        $this->recordJobLifecycleMetrics($jobName, $queue, $durationMs);

        $this->logger->info('Job completed', [
            'job_name' => $jobName,
            'queue' => $queue,
            'job_id' => $jobId,
            'duration_ms' => $durationMs,
            'attempt' => $attempt
        ]);
    }

    public function recordJobFailed(
        string $jobName,
        string $queue,
        string $jobId,
        float $durationMs,
        int $attempt,
        string $error,
        bool $willRetry
    ): void {
        $labels = array_merge($this->jobLabels, [
            'result' => 'failed',
            'will_retry' => $willRetry ? 'true' : 'false',
            'error_type' => $this->classifyJobError($error)
        ]);

        $this->statsd->increment('jobs.failed.total', 1, $labels);

        $this->statsd->gauge('jobs.in_progress', -1, [
            'job_name' => $jobName,
            'queue' => $queue
        ]);

        $this->statsd->histogram('jobs.duration_seconds', $durationMs / 1000, $labels);

        if ($willRetry) {
            $this->recordJobRetry($jobName, $queue, $attempt);
        } else {
            $this->recordJobDeadLetter($jobName, $queue, $error);
        }

        $this->checkJobFailureThreshold($jobName, $queue);

        $this->logger->error('Job failed', [
            'job_name' => $jobName,
            'queue' => $queue,
            'job_id' => $jobId,
            'duration_ms' => $durationMs,
            'attempt' => $attempt,
            'error' => $error,
            'will_retry' => $willRetry
        ]);
    }

    private function classifyJobError(string $error): string
    {
        $error = strtolower($error);

        if (str_contains($error, 'timeout')) {
            return 'timeout';
        }

        if (str_contains($error, 'memory') || str_contains($error, 'oom')) {
            return 'memory_exceeded';
        }

        if (str_contains($error, 'exception')) {
            return 'unhandled_exception';
        }

        if (str_contains($error, 'connection') || str_contains($error, 'refused')) {
            return 'connection_error';
        }

        if (str_contains($error, 'validation')) {
            return 'validation_error';
        }

        if (str_contains($error, 'duplicate')) {
            return 'duplicate_key';
        }

        if (str_contains($error, 'constraint') || str_contains($error, 'foreign key')) {
            return 'database_constraint';
        }

        return 'unknown_error';
    }

    private function recordJobRetry(string $jobName, string $queue, int $attempt): void
    {
        $this->statsd->increment('jobs.retries.total', 1, [
            'job_name' => $jobName,
            'queue' => $queue,
            'attempt' => (string)$attempt
        ]);

        $this->statsd->gauge('jobs.queued.in_flight', 1, [
            'job_name' => $jobName,
            'queue' => $queue
        ]);
    }

    private function recordJobDeadLetter(string $jobName, string $queue, string $error): void
    {
        $this->statsd->increment('jobs.dead_letter.total', 1, [
            'job_name' => $jobName,
            'queue' => $queue
        ]);

        $this->logger->warning('Job moved to dead letter queue', [
            'job_name' => $jobName,
            'queue' => $queue,
            'error' => $error
        ]);
    }

    private function checkJobFailureThreshold(string $jobName, string $queue): void
    {
        $recentFailures = $this->statsd->getCounterValue(
            'jobs.failed.total',
            ['job_name' => $jobName, 'queue' => $queue]
        );

        $recentTotal = $this->statsd->getCounterValue(
            'jobs.completed.total',
            ['job_name' => $jobName, 'queue' => $queue]
        ) + $this->statsd->getCounterValue(
            'jobs.failed.total',
            ['job_name' => $jobName, 'queue' => $queue]
        );

        if ($recentTotal === 0) {
            return;
        }

        $failureRate = $recentFailures / $recentTotal;

        if ($failureRate > 0.2) {
            $this->statsd->increment('jobs.alerts.total', 1, [
                'alert_type' => 'high_failure_rate',
                'job_name' => $jobName,
                'queue' => $queue
            ]);

            $this->logger->critical('Job failure rate threshold exceeded', [
                'job_name' => $jobName,
                'queue' => $queue,
                'failure_rate' => round($failureRate * 100, 2)
            ]);
        }
    }

    private function recordJobLifecycleMetrics(string $jobName, string $queue, float $durationMs): void
    {
        $now = time();

        $queuedDuration = $this->getQueuedDuration($jobName, $queue);

        $this->statsd->histogram('jobs.queued_duration_seconds', $queuedDuration, [
            'job_name' => $jobName,
            'queue' => $queue
        ]);

        $this->statsd->histogram('jobs.throughput_per_minute', $this->calculateThroughput($jobName, $queue), [
            'job_name' => $jobName,
            'queue' => $queue
        ]);
    }

    private function getQueueDepth(string $queue): int
    {
        return 0;
    }

    private function getQueuedDuration(string $jobName, string $queue): float
    {
        return 0.0;
    }

    private function calculateThroughput(string $jobName, string $queue): float
    {
        return 0.0;
    }

    public function recordWorkerHeartbeat(string $workerId, string $queue, int $jobsProcessed): void
    {
        $this->statsd->gauge('workers.last_seen', time(), [
            'worker_id' => $workerId,
            'queue' => $queue
        ]);

        $this->statsd->gauge('workers.jobs_processed', $jobsProcessed, [
            'worker_id' => $workerId,
            'queue' => $queue
        ]);
    }

    public function recordWorkerShutdown(string $workerId, string $queue, string $reason): void
    {
        $this->statsd->decrement('workers.active', 1, [
            'worker_id' => $workerId,
            'queue' => $queue
        ]);

        $this->logger->info('Worker shutdown', [
            'worker_id' => $workerId,
            'queue' => $queue,
            'reason' => $reason
        ]);
    }
}
