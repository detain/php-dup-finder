<?php
declare(strict_types=1);

namespace Phpdup\Server;

/**
 * In-memory job queue for the phpdup REST API.
 *
 * Each job carries its own state, request payload, and (eventually)
 * the analysis report. Designed for single-process use under
 * `phpdup serve` — for production-grade work queue semantics, swap
 * in Redis-backed storage; the interface stays the same.
 *
 * Bounded + TTL behaviour
 * -----------------------
 * The queue is capped at {@see MAX_JOBS} entries. When a new job is
 * added and the cap is reached, the oldest completed/failed job is
 * evicted first (pending/running jobs are never auto-evicted).
 *
 * Completed and failed jobs are also subject to a TTL
 * ({@see JOB_TTL_SECONDS}). On every mutating operation the oldest
 * TTL-expired completed/failed entry is purged so memory is reclaimed
 * even when the queue is below capacity.
 *
 * Results are stored as summaries — only `files`, `blocks`, `clusters`,
 * and the config are retained — never the full JsonReporter output.
 */
final class JobQueue
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    /** Maximum number of jobs to retain. */
    public const MAX_JOBS = 100;

    /** TTL for completed/failed jobs in seconds (1 hour). */
    public const JOB_TTL_SECONDS = 3600;

    /** @var array<string, array{status:string, payload:array<string, mixed>, result:?array<string, mixed>, error:?string, created_at:float, completed_at:?float}> */
    private array $jobs = [];

    /**
     * Injectable clock for testing.
     *
     * @return float Unix timestamp with microseconds.
     */
    protected function now(): float
    {
        return microtime(true);
    }

    /**
     * Add a new job to the queue and return its opaque identifier.
     *
     * The id is derived from {@see random_bytes()} so callers cannot
     * predict or guess existing job ids, which protects status reads
     * against trivial enumeration even though the queue itself is
     * single-process.
     *
     * When the queue is at {@see MAX_JOBS} capacity, the oldest
     * completed/failed entry is evicted before the new one is added.
     *
     * @param array<string,mixed> $payload
     * @return string Hex-encoded 16-character random id.
     */
    public function enqueue(array $payload): string
    {
        $this->evictStale();

        if (count($this->jobs) >= self::MAX_JOBS) {
            $this->evictOldestCompletedOrFailed();
        }

        $id = bin2hex(random_bytes(8));
        $this->jobs[$id] = [
            'status'       => self::STATUS_PENDING,
            'payload'      => $payload,
            'result'       => null,
            'error'        => null,
            'created_at'   => $this->now(),
            'completed_at' => null,
        ];
        return $id;
    }

    /**
     * Transition a known job to the {@see JobQueue::STATUS_RUNNING} state.
     *
     * Unknown ids are ignored on purpose — the API contract is "best
     * effort, no exceptions for missing rows".
     */
    public function markRunning(string $id): void
    {
        $this->evictStale();

        if (isset($this->jobs[$id])) {
            $this->jobs[$id]['status'] = self::STATUS_RUNNING;
        }
    }

    /**
     * Mark a job as finished and store a summary of the analysis result.
     *
     * Only the summary fields (`files`, `blocks`, `clusters`, `config`)
     * are stored — not the full JsonReporter output. This keeps memory
     * bounded regardless of report size.
     *
     * @param array<string,mixed> $summary Reduced result with keys: files, blocks, clusters, config.
     */
    public function markCompleted(string $id, array $summary): void
    {
        $this->evictStale();

        if (isset($this->jobs[$id])) {
            $this->jobs[$id]['status']       = self::STATUS_COMPLETED;
            $this->jobs[$id]['result']       = $summary;
            $this->jobs[$id]['completed_at'] = $this->now();
        }
    }

    /**
     * Mark a job as failed and record the error message for later polling.
     */
    public function markFailed(string $id, string $error): void
    {
        $this->evictStale();

        if (isset($this->jobs[$id])) {
            $this->jobs[$id]['status']       = self::STATUS_FAILED;
            $this->jobs[$id]['error']        = $error;
            $this->jobs[$id]['completed_at'] = $this->now();
        }
    }

    /**
     * Look up a job by id.
     *
     * @return array{status:string, payload:array<string,mixed>, result:?array<string,mixed>, error:?string}|null
     */
    public function get(string $id): ?array
    {
        $job = $this->jobs[$id] ?? null;
        if ($job === null) {
            return null;
        }
        return [
            'status'  => $job['status'],
            'payload' => $job['payload'],
            'result'  => $job['result'],
            'error'   => $job['error'],
        ];
    }

    /**
     * Atomically dequeue the oldest pending job and mark it as running.
     *
     * Returns null if no pending jobs exist. This is the companion of
     * ack()/nack() — workers call dequeue() to claim a job, then
     * call ack() on success or nack() on failure.
     *
     * @return array{id:string, payload:array<string,mixed>}|null
     */
    public function dequeue(): ?array
    {
        $this->evictStale();

        $oldestId  = null;
        $oldestAge = PHP_FLOAT_MAX;

        foreach ($this->jobs as $id => $job) {
            if ($job['status'] === self::STATUS_PENDING) {
                if ($job['created_at'] < $oldestAge) {
                    $oldestAge = $job['created_at'];
                    $oldestId  = $id;
                }
            }
        }

        if ($oldestId === null) {
            return null;
        }

        $this->jobs[$oldestId]['status'] = self::STATUS_RUNNING;
        return [
            'id'      => $oldestId,
            'payload' => $this->jobs[$oldestId]['payload'],
        ];
    }

    /**
     * Acknowledge successful completion of a job.
     *
     * Stores the result summary and transitions the job to completed.
     *
     * @param array<string,mixed> $summary Reduced result with keys: files, blocks, clusters, config.
     */
    public function ack(string $id, array $summary): void
    {
        $this->evictStale();

        if (isset($this->jobs[$id])) {
            $this->jobs[$id]['status']       = self::STATUS_COMPLETED;
            $this->jobs[$id]['result']       = $summary;
            $this->jobs[$id]['completed_at'] = $this->now();
        }
    }

    /**
     * Negative-acknowledge a job, marking it as failed.
     *
     * @param string $id Job id.
     */
    public function nack(string $id): void
    {
        $this->evictStale();

        if (isset($this->jobs[$id])) {
            $this->jobs[$id]['status']       = self::STATUS_FAILED;
            $this->jobs[$id]['error']        = 'worker processing failed';
            $this->jobs[$id]['completed_at'] = $this->now();
        }
    }

    /**
     * Get the status of a job including result/error if available.
     *
     * @return array{status:string, result:?array<string,mixed>, error:?string}|null
     */
    public function status(string $id): ?array
    {
        $job = $this->jobs[$id] ?? null;
        if ($job === null) {
            return null;
        }
        return [
            'status' => $job['status'],
            'result' => $job['result'],
            'error'  => $job['error'],
        ];
    }

    /**
     * Return a list of all jobs ordered by creation time (oldest first).
     *
     * @return array<string, array{status:string, created_at:float, completed_at:?float}>
     */
    public function list(): array
    {
        $this->evictStale();

        $result = [];
        foreach ($this->jobs as $id => $job) {
            $result[$id] = [
                'status'       => $job['status'],
                'created_at'   => $job['created_at'],
                'completed_at' => $job['completed_at'],
            ];
        }
        return $result;
    }

    /**
     * Evict all completed/failed jobs that have exceeded the TTL.
     *
     * Called automatically before every mutating operation; can also be
     * called manually to force a cleanup sweep.
     */
    public function evictStale(): void
    {
        $cutoff = $this->now() - self::JOB_TTL_SECONDS;

        foreach ($this->jobs as $id => $job) {
            if (
                ($job['status'] === self::STATUS_COMPLETED || $job['status'] === self::STATUS_FAILED)
                && $job['completed_at'] !== null
                && $job['completed_at'] < $cutoff
            ) {
                unset($this->jobs[$id]);
            }
        }
    }

    /**
     * Remove the oldest completed or failed job from the queue.
     *
     * If no completed/failed jobs exist this is a no-op (the new job
     * will still be added, potentially exceeding MAX_JOBS until the
     * next eviction pass).
     */
    private function evictOldestCompletedOrFailed(): void
    {
        $oldestId  = null;
        $oldestAge = PHP_FLOAT_MAX;

        foreach ($this->jobs as $id => $job) {
            if ($job['status'] === self::STATUS_COMPLETED || $job['status'] === self::STATUS_FAILED) {
                $completedAt = $job['completed_at'] ?? $job['created_at'];
                if ($completedAt < $oldestAge) {
                    $oldestAge = $completedAt;
                    $oldestId  = $id;
                }
            }
        }

        if ($oldestId !== null) {
            unset($this->jobs[$oldestId]);
        }
    }

    /**
     * Force-evict every completed/failed job regardless of TTL.
     *
     * Useful for administrative cleanup endpoints.
     */
    public function cleanup(): void
    {
        foreach ($this->jobs as $id => $job) {
            if ($job['status'] === self::STATUS_COMPLETED || $job['status'] === self::STATUS_FAILED) {
                unset($this->jobs[$id]);
            }
        }
    }
}
