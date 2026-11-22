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
 * The queue is capped at {@see MAX_JOBS} entries and the cap is a hard
 * ceiling (once-002): when a new job is added at capacity, room is made
 * by class preference — the oldest completed/failed job first, then the
 * oldest pending job, and only when every row is live work the oldest
 * running job. The historic posture (never auto-evict pending/running,
 * append anyway) let a flood grow the queue unboundedly past the cap;
 * the ceiling closes that, and every capacity eviction is recorded as a
 * visible note: the dispatching request's response carries the displaced
 * ids, and a poll of an evicted id explains the eviction (the HTTP layer
 * surfaces {@see evictionNote()} in its 404 body).
 *
 * Completed and failed jobs are also subject to a TTL
 * ({@see JOB_TTL_SECONDS}). On every mutating operation the oldest
 * TTL-expired completed/failed entry is purged so memory is reclaimed
 * even when the queue is below capacity. TTL purges are time-based
 * expiry, not capacity displacement, so they are not ledger events.
 *
 * Results are stored as summaries — only `files`, `blocks`, `clusters`,
 * and the config are retained — never the full JsonReporter output.
 *
 * Payload retention
 * -----------------
 * Raw request payloads (which may carry arbitrarily large `paths`
 * lists) are NOT retained. `enqueue()` reduces each payload to a
 * fixed-size `{digest, bytes}` descriptor: the SHA-256 of the wire
 * frame (the exact byte encoding the async dispatch leg writes to the
 * worker socket; serialized in insertion order, so the digest is
 * key-order sensitive) plus its length. Retained queue memory per job
 * is therefore bounded by the descriptor and the result summary,
 * independent of payload size.
 *
 * Consequence for consumers: anything that needs the payload contents
 * after admission must snapshot it at dispatch time. The async leg
 * already does exactly that — `Application::enqueueJob()` serializes
 * the `RUN <id> <json>` frame from its own request-local copy and
 * writes it to the worker within the same request, before responding
 * 202; the queue row is never read back for dispatch.
 * {@see JobQueue::payloadDigest()} lets a result writer prove the
 * frame it ran matches the descriptor the queue kept. The `/api/jobs`
 * status routes are unaffected (they never exposed the payload);
 * `get()`/`dequeue()` surface the descriptor under `payload`.
 */
final class JobQueue
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    /** Maximum number of jobs to retain (hard ceiling; see class docblock). */
    public const MAX_JOBS = 100;

    /** TTL for completed/failed jobs in seconds (1 hour). */
    public const JOB_TTL_SECONDS = 3600;

    /**
     * Upper bound on retained capacity-eviction notes (ring buffer — the
     * oldest note is dropped first). Bounded by design: the ledger exists
     * to make recent displacements visible, not to archive history.
     */
    public const MAX_EVICTION_NOTES = 64;

    /** @var array<string, array{status:string, payload:array{digest:string, bytes:int}, result:?array<string, mixed>, error:?string, created_at:float, completed_at:?float}> */
    private array $jobs = [];

    /** @var array<string, array{status:string, reason:string, at:float}> capacity evictions, oldest note first */
    private array $evictions = [];

    /** @var list<array{job_id:string, status:string, reason:string}> capacity evictions caused by the most recent enqueue() */
    private array $lastEvictions = [];

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
     * When the queue is at {@see MAX_JOBS} capacity, room is made by
     * {@see makeRoomAtCap()}: oldest terminal row first, then oldest
     * pending, then oldest running — each displacement recorded as a
     * visible note (see class docblock).
     *
     * The retained `payload` is a bounded `{digest, bytes}` descriptor
     * of the wire frame, not the raw array (see class docblock).
     *
     * @param array<string,mixed> $payload
     * @return string Hex-encoded 16-character random id.
     */
    public function enqueue(array $payload): string
    {
        $this->evictStale();
        $this->lastEvictions = [];
        $this->makeRoomAtCap();

        $id = bin2hex(random_bytes(8));
        $this->jobs[$id] = [
            'status'       => self::STATUS_PENDING,
            'payload'      => self::describePayload($payload),
            'result'       => null,
            'error'        => null,
            'created_at'   => $this->now(),
            'completed_at' => null,
        ];
        return $id;
    }

    /**
     * SHA-256 identity of a payload's wire frame.
     *
     * The frame is encoded with {@see JSON_UNESCAPED_SLASHES} in the
     * payload's insertion order — the same flags and byte order the
     * async dispatch leg uses for the worker socket — so the digest
     * identifies the exact wire bytes, key order included: two
     * semantically equal payloads whose keys differ in order digest
     * differently. A leg that snapshots the frame can verify it
     * against the descriptor the queue retained.
     *
     * @param array<string,mixed> $payload
     */
    public static function payloadDigest(array $payload): string
    {
        return hash('sha256', self::wireFrameJson($payload));
    }

    /**
     * Reduce a payload to its fixed-size retention descriptor.
     *
     * @param array<string,mixed> $payload
     * @return array{digest:string, bytes:int}
     */
    private static function describePayload(array $payload): array
    {
        $wire = self::wireFrameJson($payload);
        return [
            'digest' => hash('sha256', $wire),
            'bytes'  => strlen($wire),
        ];
    }

    /**
     * Exact wire byte-form a payload is digested over.
     *
     * Serialized in insertion order, NOT key-sorted: the digest is a
     * fingerprint of the bytes the async leg would write to the worker
     * socket, so key order is part of the identity by design.
     *
     * @param array<string,mixed> $payload
     */
    private static function wireFrameJson(array $payload): string
    {
        try {
            return (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            // Non-encodable payloads ARE reachable through `phpdup serve`:
            // `json_decode('{"limit":1e400}')` yields `float(INF)`, which
            // JSON_THROW_ON_ERROR rejects. Request-level handling of such
            // bodies (the throw-after-mint crash leg) is owned by shell-S01
            // as crash-proof request handling — pre-existing at this commit,
            // neither introduced nor regressed here. The partial-encode
            // fallback keeps enqueue() itself bounded and total: a caller
            // passing INF/NAN/cycles settles on a stable lossy descriptor
            // instead of an exception.
            return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
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
     * @return array{status:string, payload:array{digest:string, bytes:int}, result:?array<string,mixed>, error:?string}|null
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
     * @return array{id:string, payload:array{digest:string, bytes:int}}|null
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
     * Make room at the cap, honouring the class preference documented on
     * the class: terminal rows first, then the oldest pending job, and
     * only when every row is live work the oldest running job. Each
     * displacement is recorded in the bounded visible-note ledger and in
     * {@see lastEvictions()} for the request that caused it.
     *
     * The while-loop terminates by construction: every round removes one
     * row, so it stops the instant the queue drops below the cap (and a
     * queue empty below the cap exits through the null-victim guard).
     */
    private function makeRoomAtCap(): void
    {
        while (count($this->jobs) >= self::MAX_JOBS) {
            $victim = $this->oldestEvictable();
            if ($victim === null) {
                return; // unreachable while count >= MAX_JOBS; fail-safe exit
            }

            [$id, $status] = $victim;
            unset($this->jobs[$id]);

            $reason = 'evicted from queue at capacity (' . self::MAX_JOBS . ' jobs) to admit a newer job';
            $this->evictions[$id] = ['status' => $status, 'reason' => $reason, 'at' => $this->now()];
            if (count($this->evictions) > self::MAX_EVICTION_NOTES) {
                unset($this->evictions[array_key_first($this->evictions)]);
            }
            $this->lastEvictions[] = ['job_id' => $id, 'status' => $status, 'reason' => $reason];
        }
    }

    /**
     * The row the cap should displace: first non-empty class (in
     * preference order) contributes its oldest member. Within the terminal
     * class the age is the completion instant (falling back to creation,
     * exactly as the historic single-eviction leg measured); within the
     * live classes it is the creation instant. Ties resolve by insertion
     * order — the strict `<` comparison keeps the earlier-created row.
     *
     * @return array{0:string, 1:string}|null [job id, status at eviction]
     */
    private function oldestEvictable(): ?array
    {
        /** @var list<list<string>> $classes */
        $classes = [
            [self::STATUS_COMPLETED, self::STATUS_FAILED],
            [self::STATUS_PENDING],
            [self::STATUS_RUNNING],
        ];

        foreach ($classes as $statuses) {
            $terminal = $statuses[0] !== self::STATUS_PENDING && $statuses[0] !== self::STATUS_RUNNING;
            $bestId   = null;
            $bestAge  = PHP_FLOAT_MAX;
            foreach ($this->jobs as $id => $job) {
                if (!in_array($job['status'], $statuses, true)) {
                    continue;
                }
                $age = $terminal
                    ? ($job['completed_at'] ?? $job['created_at'])
                    : $job['created_at'];
                if ($age < $bestAge) {
                    $bestAge = $age;
                    $bestId  = $id;
                }
            }
            if ($bestId !== null) {
                return [$bestId, $this->jobs[$bestId]['status']];
            }
        }

        return null;
    }

    /**
     * Visible note for a job the cap evicted, or null for ids the queue
     * never recorded a displacement against (unknown ids and TTL purges
     * keep the plain not-found story).
     */
    public function evictionNote(string $id): ?string
    {
        return isset($this->evictions[$id]) ? $this->evictions[$id]['reason'] : null;
    }

    /**
     * Capacity evictions caused by the most recent {@see enqueue()}
     * (empty when admission needed no room). The HTTP layer surfaces
     * these on the dispatch response so the submitting client sees exactly
     * what its job displaced.
     *
     * @return list<array{job_id:string, status:string, reason:string}>
     */
    public function lastEvictions(): array
    {
        return $this->lastEvictions;
    }

    /**
     * The bounded capacity-eviction ledger (oldest note first), keyed by
     * evicted job id.
     *
     * @return array<string, array{status:string, reason:string, at:float}>
     */
    public function evictions(): array
    {
        return $this->evictions;
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
