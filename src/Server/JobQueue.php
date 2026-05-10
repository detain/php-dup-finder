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
 */
final class JobQueue
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    /** @var array<string, array{status:string,payload:array<string,mixed>,result:?array<string,mixed>,error:?string}> */
    private array $jobs = [];

    /**
     * Add a new job to the queue and return its opaque identifier.
     *
     * The id is derived from {@see random_bytes()} so callers cannot
     * predict or guess existing job ids, which protects status reads
     * against trivial enumeration even though the queue itself is
     * single-process.
     *
     * @param array<string,mixed> $payload
     * @return string Hex-encoded 16-character random id.
     */
    public function enqueue(array $payload): string
    {
        $id = bin2hex(random_bytes(8));
        $this->jobs[$id] = [
            'status'  => self::STATUS_PENDING,
            'payload' => $payload,
            'result'  => null,
            'error'   => null,
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
        if (isset($this->jobs[$id])) {
            $this->jobs[$id]['status'] = self::STATUS_RUNNING;
        }
    }

    /**
     * Mark a job as finished and store its decoded analysis result.
     *
     * @param array<string,mixed> $result
     */
    public function markCompleted(string $id, array $result): void
    {
        if (isset($this->jobs[$id])) {
            $this->jobs[$id]['status'] = self::STATUS_COMPLETED;
            $this->jobs[$id]['result'] = $result;
        }
    }

    /**
     * Mark a job as failed and record the error message for later polling.
     */
    public function markFailed(string $id, string $error): void
    {
        if (isset($this->jobs[$id])) {
            $this->jobs[$id]['status'] = self::STATUS_FAILED;
            $this->jobs[$id]['error']  = $error;
        }
    }

    /**
     * Look up a job by id.
     *
     * @return array{status:string,payload:array<string,mixed>,result:?array<string,mixed>,error:?string}|null
     */
    public function get(string $id): ?array
    {
        return $this->jobs[$id] ?? null;
    }
}
