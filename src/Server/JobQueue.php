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

    /** @param array<string,mixed> $payload */
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

    public function markRunning(string $id): void
    {
        if (isset($this->jobs[$id])) {
            $this->jobs[$id]['status'] = self::STATUS_RUNNING;
        }
    }

    /**
     * @param array<string,mixed> $result
     */
    public function markCompleted(string $id, array $result): void
    {
        if (isset($this->jobs[$id])) {
            $this->jobs[$id]['status'] = self::STATUS_COMPLETED;
            $this->jobs[$id]['result'] = $result;
        }
    }

    public function markFailed(string $id, string $error): void
    {
        if (isset($this->jobs[$id])) {
            $this->jobs[$id]['status'] = self::STATUS_FAILED;
            $this->jobs[$id]['error']  = $error;
        }
    }

    /** @return array{status:string,payload:array<string,mixed>,result:?array<string,mixed>,error:?string}|null */
    public function get(string $id): ?array
    {
        return $this->jobs[$id] ?? null;
    }
}
