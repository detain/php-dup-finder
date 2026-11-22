<?php
declare(strict_types=1);

namespace Phpdup\Server;

use JsonException;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\JsonReporter;
use Symfony\Component\Console\Output\NullOutput;

/**
 * HTTP application handler — pure PHP, transport-agnostic.
 *
 * Takes a method+path+body and returns an associative response array
 * `{status, headers, body}`. The serve command wraps this in a
 * stream_socket_server loop; the same Application is testable without
 * any networking stack.
 *
 * Routes:
 *   GET  /healthz               → 200, plain "ok"
 *   POST /analyze               → run phpdup synchronously on a posted
 *                                 paths[] payload, return JSON report
 *   POST /jobs                  → enqueue an analysis, return job id
 *   GET  /jobs/{id}             → fetch job status / result
 *   GET  /api/jobs              → list all jobs
 *   GET  /api/jobs/{id}         → fetch job status (status-only)
 *   GET  /api/jobs/{id}/result  → fetch job result file
 *
 * When a worker socket is configured (async mode), POST /jobs dispatches
 * to the background worker and returns 202 immediately. The dispatch only
 * waits for the worker's ACCEPT-ack (shell-032: the worker answers `OK
 * <jobId>` the instant it admits the frame, before running the job), never
 * for the analysis itself — so a job that takes longer than any socket
 * timeout is no longer mistaken for a dispatch failure. Completion is
 * signalled by the result file appearing: every status/result read first
 * reconciles the queue row against the results directory, transitioning the
 * job to completed the moment its result file is present, and to failed once
 * the {@see self::ASYNC_RESULT_DEADLINE_SECONDS} absence-deadline lapses
 * without one (a crashed worker leaves no file — the lane-pinned choice for
 * the mid-job failure signal, over an explicit ERR frame). Without a worker
 * socket, analysis runs synchronously (sync mode for dev/demo).
 *
 * Security notes
 * --------------
 * - All JSON parsing uses {@see JSON_THROW_ON_ERROR} and converts
 *   malformed payloads into a 400 response (no silent fallthrough).
 * - The route table is a fixed allow-list; methods/paths outside the
 *   table return 404 *before* the body is decoded.
 * - {@see Application::MAX_BODY_BYTES} caps decoded JSON to a sane
 *   ceiling so a misbehaving client cannot eat unbounded memory.
 *   ServeCommand applies the matching limit at the HTTP layer.
 * - When a token is configured, all requests must present a matching
 *   `Authorization: Bearer <token>` header. Missing or mismatched
 *   tokens result in a 401 response.
 * - Scanned paths are confined to within {@see $serveRoot} via
 *   realpath canonicalization, for BOTH `POST /analyze` and
 *   `POST /jobs`: payload paths that are non-strings, contain NUL bytes,
 *   are absolute, contain `..`, or resolve outside the root are rejected
 *   with a 400 response before any job is minted. Payloads that set a
 *   {@see self::REFUSED_PAYLOAD_KEYS} field are refused with the same
 *   400-before-mint posture, mirroring the worker's RUN-frame gate.
 *   In async mode the worker independently re-runs both checks
 *   ({@see AnalysisWorker}) using the serve root handed to it as a
 *   spawn argument — defence in depth.
 */
final class Application
{
    /** Hard ceiling on accepted JSON body size (16 MiB). */
    public const MAX_BODY_BYTES = 16 * 1024 * 1024;

    /**
     * Job-payload fields that must never travel on the wire.
     *
     * The list is the single source of truth shared by both admission
     * layers: HTTP `POST /jobs` refuses such payloads with a 400 before
     * minting a job ({@see self::enqueueJob()}) and the worker applies the
     * identical gate to every RUN frame ({@see AnalysisWorker::processJob()}).
     * ML-URL admission itself stays on the CLI/config path behind
     * MlClient::isAllowedUrl()'s fail-closed validation gate.
     */
    public const REFUSED_PAYLOAD_KEYS = ['ml_pair_url'];

    /**
     * Serial worker count for the synchronous `/analyze` (and worker-less
     * `/jobs`) pipeline (shell-032).
     *
     * The serve process handles requests serially; running the analysis
     * "auto" would spawn `detectCpuCount()` forked children for EVERY
     * request — a fork storm on a many-core box (a 64-core machine forked
     * 64 children to scan a handful of posted paths). Forcing the sync path
     * to a single worker keeps small payloads cheap while producing a
     * byte-identical report; an operator who wants parallel analysis on the
     * sync route opts in with `--serve-workers N` (still clamped by the
     * shell-S10 `WorkerPool::clampWorkers()` ceiling). The async worker
     * keeps its own auto-parallelism because it runs one job at a time in a
     * dedicated background process, so its fan-out is not a per-request cost.
     */
    public const SERVE_WORKERS_SERIAL = 1;

    /**
     * Absence-deadline for an async job's result file (shell-032, lane
     * choice pinned: deadline over an explicit ERR frame).
     *
     * After the worker's accept-ack the server never blocks on the analysis;
     * completion is the result file appearing. A worker that crashes
     * mid-job leaves no file, so a still-pending/running job whose result
     * has not shown up within this window is honestly failed on the next
     * status read. 300 s is generous enough that a slow-but-successful
     * analysis on a large fixture corpus is never mistaken for a dead
     * worker, yet bounded so a crashed one does not linger as "running"
     * forever. Tests shrink it via {@see self::$asyncResultDeadlineSeconds}.
     */
    public const ASYNC_RESULT_DEADLINE_SECONDS = 300;

    /**
     * Bound on how long the dispatcher waits for the worker's ACCEPT-ack
     * (shell-032). The ack is written the instant the frame is admitted, so
     * this bounds a wedged socket — not the analysis. A second or two is
     * ample; the whole point of the change is that this number no longer
     * caps how long a job may run.
     */
    public const DISPATCH_ACK_TIMEOUT_SECONDS = 5;

    /**
     * @param JobQueue       $queue         Job queue used by the /jobs routes.
     * @param string|null    $serveRoot     Absolute path to which scanning is confined.
     * @param string|null    $token         Bearer token required for all requests when set.
     * @param string|null    $workerSocket  Path to the Unix socket for async job dispatch.
     * @param string|null    $resultDir     Directory where result files are written by the worker.
     * @param bool           $useCache      Sync-pipeline cache posture (shell-032); default false
     *                                      preserves the historic no-cache serve behaviour,
     *                                      surfaced as the `--serve-cache` flag.
     * @param int            $serveWorkers  Sync-pipeline worker count (shell-032); default
     *                                      {@see self::SERVE_WORKERS_SERIAL} (serial), opt up
     *                                      with `--serve-workers`.
     */
    public function __construct(
        private readonly JobQueue $queue = new JobQueue(),
        private readonly ?string $serveRoot = null,
        private readonly ?string $token = null,
        private readonly ?string $workerSocket = null,
        private readonly ?string $resultDir = null,
        private readonly bool $useCache = false,
        private readonly int $serveWorkers = self::SERVE_WORKERS_SERIAL,
    ) {
    }

    /**
     * Test seam for the async accept-ack exchange (shell-032): null performs
     * the real socket handshake against {@see $workerSocket}; a probe returns
     * the worker's first reply line (the trimmed accept-ack, a `FAIL …`, or
     * null for an unreachable/timed-out socket) so the ack parsing, the
     * accepted/rejected verdict and every queue transition below run
     * unseamed — only the kernel socket I/O, which a single-threaded unit
     * cannot reproduce the async ordering of, is replaced. Mirrors how
     * ServeCommand seams its read deadline rather than its parsing.
     *
     * @var (callable(string, string): (?string))|null
     */
    public $workerAckProbe = null;

    /**
     * Test seam for the async absence-deadline in seconds (shell-032): null
     * uses {@see self::ASYNC_RESULT_DEADLINE_SECONDS}; a probe (0 or a tiny
     * window) lets units pin the crashed-worker FAILED leg without a wall
     * clock wait. Only the number is seamed; the deadline comparison and the
     * file-absence verdict run unseamed.
     */
    public ?int $asyncResultDeadlineSeconds = null;

    /**
     * Test seam for the reconcile clock (shell-032): null uses
     * `microtime(true)`; a probe returns a fixed/advanced timestamp so the
     * deadline legs are deterministic. Only the timestamp read is seamed; the
     * elapsed comparison, result-file lookup and queue writes run unseamed.
     *
     * @var (callable(): float)|null
     */
    public $asyncClock = null;

    /** @var array<string, float> dispatch timestamp per async job id, for the absence-deadline. */
    private array $asyncDispatchedAt = [];

    /**
     * Dispatch a single HTTP request to the appropriate route handler.
     *
     * @param string               $method  HTTP method (GET/POST/…).
     * @param string               $path    Request path (no query string).
     * @param string               $body    Raw request body.
     * @param array<string,string> $headers Request headers (lowercase keys).
     * @return array{status:int, headers: array<string,string>, body: string}
     */
    public function handle(string $method, string $path, string $body, array $headers = []): array
    {
        // Bearer token authentication: when a token is configured, every
        // request must present a matching Authorization header.
        if ($this->token !== null) {
            $authHeader = $this->findAuthorizationHeader($headers);
            if (!$this->isValidBearerToken($authHeader)) {
                return $this->plain(401, 'Unauthorized');
            }
        }

        if ($method === 'GET' && $path === '/healthz') {
            return $this->plain(200, 'ok');
        }
        if ($method === 'POST' && $path === '/analyze') {
            try {
                $payload = $this->jsonBody($body);
            } catch (JsonException $e) {
                return $this->json(400, ['error' => 'invalid JSON: ' . $e->getMessage()]);
            }
            return $this->runAnalyze($payload);
        }
        if ($method === 'POST' && $path === '/jobs') {
            try {
                $payload = $this->jsonBody($body);
            } catch (JsonException $e) {
                return $this->json(400, ['error' => 'invalid JSON: ' . $e->getMessage()]);
            }
            return $this->enqueueJob($payload);
        }
        if ($method === 'GET' && preg_match('#^/jobs/([0-9a-f]+)$#', $path, $m) === 1) {
            $this->reconcileAsyncResult($m[1]);
            $job = $this->queue->get($m[1]);
            if ($job === null) {
                return $this->json(404, ['error' => 'job not found']);
            }
            return $this->json(200, $job);
        }
        // Async API routes
        if ($method === 'GET' && $path === '/api/jobs') {
            $this->reconcileAllAsyncResults();
            return $this->json(200, $this->queue->list());
        }
        if ($method === 'GET' && preg_match('#^/api/jobs/([0-9a-f]+)$#', $path, $m) === 1) {
            $this->reconcileAsyncResult($m[1]);
            $status = $this->queue->status($m[1]);
            if ($status === null) {
                return $this->json(404, ['error' => 'job not found']);
            }
            return $this->json(200, $status);
        }
        if ($method === 'GET' && preg_match('#^/api/jobs/([0-9a-f]+)/result$#', $path, $m) === 1) {
            $this->reconcileAsyncResult($m[1]);
            return $this->getJobResult($m[1]);
        }
        return $this->json(404, ['error' => 'unknown route']);
    }

    /**
     * Run the full analysis pipeline against a parsed payload and
     * return the JSON report as an HTTP response array.
     *
     * @param array<string,mixed> $payload Decoded request body.
     * @return array{status:int, headers: array<string,string>, body: string}
     */
    private function runAnalyze(array $payload): array
    {
        // Validate payload shape and serve-root confinement before scanning.
        if (($violation = $this->pathViolation($payload['paths'] ?? null)) !== null) {
            return $violation;
        }
        /** @var list<string> $paths */
        $paths = $payload['paths'];
        try {
            $config = $this->buildAnalyzeConfig($paths);
            $state = new PipelineState($config);
            $out   = new NullOutput();
            (new ScanningStage())->run($state, $out);
            (new PreprocessStage(useCache: $this->useCache))->run($state, $out);
            (new ClusterStage(exactOnly: true, useClusterCache: $this->useCache))->run($state, $out);
            (new RefactorStage(useCache: $this->useCache))->run($state, $out);
            $report = new \Phpdup\Reporting\Report(
                files: count($state->files),
                blocks: count($state->blocks),
                parseErrors: $state->parseErrors,
                clusters: (new \Phpdup\Reporting\Ranker($config->minClusterImpact))->rank($state->clusters),
                config: $config,
            );
            return $this->json(200, (new JsonReporter())->build($report));
        } catch (\Throwable $e) {
            return $this->json(500, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Extract summary fields from a decoded JsonReporter payload.
     *
     * Only `files`, `blocks`, `clusters`, and `config` are stored in the
     * job result so the queue stays bounded even for large codebases.
     *
     * @param array<string,mixed>|null $decoded
     * @return array{files:int, blocks:int, clusters:int, config?:mixed}
     */
    private function buildSummary(?array $decoded): array
    {
        if (!is_array($decoded)) {
            return ['files' => 0, 'blocks' => 0, 'clusters' => 0];
        }
        return [
            'files'    => is_int($decoded['files'] ?? null) ? $decoded['files'] : 0,
            'blocks'   => is_int($decoded['blocks'] ?? null) ? $decoded['blocks'] : 0,
            'clusters' => is_int($decoded['clusters'] ?? null) ? $decoded['clusters'] : 0,
            'config'   => $decoded['config'] ?? null,
        ];
    }

    /**
     * Enqueue a new analysis job and dispatch to worker if available.
     *
     * Payload paths are validated against the serve root *before* a job is
     * minted, so a rejected request leaves the queue untouched. Payloads
     * setting a {@see self::REFUSED_PAYLOAD_KEYS} field are refused with the
     * same 400-before-mint posture (the worker enforces the same gate on the
     * RUN frame; checking it here keeps a client admission failure from
     * surfacing as a dispatch 500 with a job minted in the queue).
     *
     * When a worker socket is configured, the job is dispatched to the
     * background worker and this returns immediately (202 Accepted).
     * When no worker is configured, runs synchronously (sync/demo mode).
     *
     * @param array<string,mixed> $payload
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private function enqueueJob(array $payload): array
    {
        if (($violation = $this->pathViolation($payload['paths'] ?? null)) !== null) {
            return $violation;
        }

        // Admission mirror of the worker's RUN-frame key gate: a payload that
        // sets a refused field is a client error (400) decided before any job
        // is minted — never a post-dispatch 500 that discards the reason.
        if (($refused = self::refusedPayloadKeys($payload)) !== []) {
            return $this->json(400, ['error' => 'job payload must not set: ' . implode(', ', $refused)]);
        }

        if ($this->workerSocket !== null && $this->resultDir !== null) {
            // Async mode: encode the dispatch frame first, so a payload that
            // cannot survive wire encoding fails without minting a job.
            // Socketpair Law: the frame carries scalars only — the payload
            // crosses as a JSON string inside `RUN <jobId> <configJson>`.
            try {
                $configJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $e) {
                return $this->json(400, ['error' => 'job payload is not wire-encodable: ' . $e->getMessage()]);
            }

            $id = $this->queue->enqueue($payload);
            if (!$this->dispatchToWorker($id, $configJson)) {
                $this->queue->markFailed($id, 'failed to dispatch to worker');
                return $this->json(500, ['error' => 'failed to dispatch job to worker']);
            }
            // shell-032: the worker's ACCEPT-ack (`OK <id>` written before it
            // runs the pipeline) proves the frame was admitted — it does NOT
            // mean the analysis finished. The job is now running in the
            // background process; its terminal state is reconciled from the
            // result file on later status reads, so this request returns 202
            // without ever blocking on the work. Record the dispatch instant
            // that anchors the crashed-worker absence-deadline.
            $this->queue->markRunning($id);
            $this->asyncDispatchedAt[$id] = $this->asyncNow();
            return $this->json(202, ['job_id' => $id]);
        }

        // Sync mode (no worker): run analysis inline
        $id = $this->queue->enqueue($payload);
        $this->queue->markRunning($id);
        $result = $this->runAnalyze($payload);
        if ($result['status'] === 200) {
            try {
                $decoded = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = null;
            }
            $summary = $this->buildSummary($decoded);
            $this->queue->ack($id, $summary);
        } else {
            $this->queue->markFailed($id, $result['body']);
        }
        return $this->json(202, ['job_id' => $id]);
    }

    /**
     * Dispatch a job to the background worker via Unix socket.
     *
     * Wire protocol: `RUN <jobId> <configJson>\n` in; the worker answers
     * `OK <jobId>\n` as an ACCEPT-ack the instant it admits the frame
     * (shell-032) — before running the pipeline. This method therefore
     * returns as soon as the frame is accepted: it never waits for (or
     * false-fails) a job that simply takes a while. A `FAIL` reply or no
     * reply (unreachable/wedged socket) is a genuine dispatch failure.
     *
     * @param string               $jobId
     * @param string               $configJson Pre-encoded payload frame (JSON string).
     * @return bool True if the worker accepted the job.
     */
    private function dispatchToWorker(string $jobId, string $configJson): bool
    {
        if ($this->workerSocket === null) {
            return false;
        }

        $message = "RUN {$jobId} {$configJson}\n";
        $ack = $this->exchangeAck($message);

        return is_string($ack) && str_starts_with($ack, 'OK ' . $jobId);
    }

    /**
     * Write a RUN frame and read the worker's single accept-ack line.
     *
     * The worker answers `OK <jobId>` the instant it admits the frame —
     * before running the pipeline — so this exchange is bounded by
     * {@see self::DISPATCH_ACK_TIMEOUT_SECONDS} for a wedged socket only,
     * never for the analysis itself. Returns the trimmed first line, or null
     * when the socket is unreachable, the write is short, or no line arrives.
     */
    private function exchangeAck(string $message): ?string
    {
        $probe = $this->workerAckProbe;
        if ($probe !== null) {
            $acked = $probe($this->workerSocket ?? '', $message);

            return $acked === null ? null : trim($acked);
        }

        $socket = @stream_socket_client('unix://' . $this->workerSocket, $errno, $errstr, 1);
        if ($socket === false) {
            return null;
        }

        try {
            $written = @fwrite($socket, $message);
            if ($written === false || $written !== strlen($message)) {
                return null;
            }
            stream_set_timeout($socket, self::DISPATCH_ACK_TIMEOUT_SECONDS);
            $response = @fgets($socket);
            if ($response === false || $response === '') {
                return null;
            }

            return trim($response);
        } finally {
            @fclose($socket);
        }
    }

    /**
     * Build the Config for a synchronous `/analyze`/`/jobs` pipeline run.
     *
     * Defaults-derived, then the shell-032 serve worker policy is applied:
     * `workers` is a root-only field that {@see Config::withOverrides()}
     * deliberately never replaces, so the copy is rebuilt through the
     * constructor with every other defaults field preserved. The default
     * {@see self::SERVE_WORKERS_SERIAL} keeps a per-request serve scan from
     * forking `detectCpuCount()` children; an explicit `--serve-workers`
     * value rides straight through (and is still clamped by the shell-S10
     * ceiling inside WorkerPool).
     *
     * @param list<string> $paths
     */
    private function buildAnalyzeConfig(array $paths): Config
    {
        $config = Config::defaults($paths);
        if ($config->workers === $this->serveWorkers) {
            return $config;
        }

        $constructor = (new \ReflectionClass(Config::class))->getConstructor();
        $args = [];
        foreach ($constructor?->getParameters() ?? [] as $param) {
            $name = $param->getName();
            $args[$name] = $name === 'workers' ? $this->serveWorkers : $config->{$name};
        }

        return new Config(...$args);
    }

    /**
     * Reconcile one async job's queue row against the results directory
     * (shell-032: completion is the result file appearing).
     *
     * A pending/running job whose result file has landed is acked completed
     * (reduced to the retained summary, exactly as the sync path does); one
     * whose absence has outlived {@see self::ASYNC_RESULT_DEADLINE_SECONDS}
     * is honestly failed — the pinned signal for a worker that died mid-job
     * (a crashed worker never writes the file, and there is no post-work ack
     * left to read). Already-terminal rows are never touched, and the
     * method is a no-op in sync mode (the inline run settled the row).
     */
    private function reconcileAsyncResult(string $jobId): void
    {
        if ($this->workerSocket === null || $this->resultDir === null) {
            return;
        }

        $job = $this->queue->get($jobId);
        if ($job === null) {
            return;
        }
        $status = $job['status'];
        if ($status !== JobQueue::STATUS_PENDING && $status !== JobQueue::STATUS_RUNNING) {
            return;
        }

        $resultFile = $this->resultDir . '/' . $jobId . '.json';
        if (is_file($resultFile)) {
            unset($this->asyncDispatchedAt[$jobId]);
            $content = @file_get_contents($resultFile);
            if ($content === false) {
                $this->queue->markFailed($jobId, 'failed to read result file');
                return;
            }
            try {
                $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                // The worker publishes atomically (temp+rename), so a present
                // file that will not decode is corrupt, not in-flight.
                $this->queue->markFailed($jobId, 'result file is not valid JSON');
                return;
            }
            $this->queue->ack($jobId, $this->buildSummary(is_array($decoded) ? $decoded : null));
            return;
        }

        $dispatchedAt = $this->asyncDispatchedAt[$jobId] ?? null;
        if ($dispatchedAt === null) {
            return; // not dispatched by this process — the worker owns it
        }
        $deadline = $this->asyncResultDeadlineSeconds ?? self::ASYNC_RESULT_DEADLINE_SECONDS;
        if ($this->asyncNow() - $dispatchedAt > $deadline) {
            unset($this->asyncDispatchedAt[$jobId]);
            $this->queue->markFailed($jobId, 'worker did not produce a result within ' . $deadline . 's');
        }
    }

    /**
     * Reconcile every still-live async job (used by the `/api/jobs` list
     * route, which reports the whole queue). No-op in sync mode.
     */
    private function reconcileAllAsyncResults(): void
    {
        if ($this->workerSocket === null || $this->resultDir === null) {
            return;
        }
        foreach ($this->queue->list() as $id => $row) {
            $status = $row['status'] ?? null;
            if ($status === JobQueue::STATUS_PENDING || $status === JobQueue::STATUS_RUNNING) {
                $this->reconcileAsyncResult((string) $id);
            }
        }
    }

    /**
     * Reconcile clock (shell-032): the seamed read, defaulting to wall time.
     * Only the timestamp source is seamable; every comparison on it is not.
     */
    private function asyncNow(): float
    {
        $clock = $this->asyncClock;
        return $clock !== null ? $clock() : microtime(true);
    }

    /**
     * Read the result file for a completed job.
     *
     * @param string $jobId
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private function getJobResult(string $jobId): array
    {
        if ($this->resultDir === null) {
            return $this->json(404, ['error' => 'result not available']);
        }

        $resultFile = $this->resultDir . '/' . $jobId . '.json';
        if (!is_file($resultFile)) {
            return $this->json(404, ['error' => 'result file not found']);
        }

        $content = @file_get_contents($resultFile);
        if ($content === false) {
            return $this->json(500, ['error' => 'failed to read result file']);
        }

        return [
            'status'  => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $content,
        ];
    }

    /**
     * Decode a JSON request body, validating size and shape.
     *
     * @return array<string,mixed>
     * @throws JsonException If the body is too large, not an object, or malformed JSON.
     */
    private function jsonBody(string $body): array
    {
        if ($body === '') {
            return [];
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new JsonException('request body exceeds ' . self::MAX_BODY_BYTES . ' bytes');
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new JsonException('expected JSON object at top level');
        }
        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    /**
     * Encode a payload as a JSON HTTP response.
     *
     * @param array<string,mixed> $payload
     * @return array{status:int, headers: array<string,string>, body: string}
     */
    private function json(int $status, array $payload): array
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            // Should never happen with well-formed inputs; fall back to a safe error envelope.
            $body = '{"error":"internal: response encoding failed"}';
            $status = 500;
        }
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
        ];
    }

    /**
     * Build a plain-text HTTP response.
     *
     * @return array{status:int, headers: array<string,string>, body: string}
     */
    private function plain(int $status, string $body): array
    {
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'text/plain'],
            'body'    => $body,
        ];
    }

    /**
     * Find the Authorization header value from a lowercase-keyed array.
     *
     * @param array<string,string> $headers
     */
    private function findAuthorizationHeader(array $headers): ?string
    {
        foreach ($headers as $key => $value) {
            if ($key === 'authorization') {
                return $value;
            }
        }
        return null;
    }

    /**
     * Check if the given Authorization header value matches the configured bearer token.
     */
    private function isValidBearerToken(?string $authHeader): bool
    {
        if ($authHeader === null) {
            return false;
        }
        if (!str_starts_with(strtolower($authHeader), 'bearer ')) {
            return false;
        }
        $token = substr($authHeader, 7);
        return hash_equals($this->token, $token);
    }

    /**
     * Validate request paths, mapping any admission failure onto an HTTP response.
     *
     * @param mixed $paths Raw `paths` value from a decoded request payload.
     * @return array{status:int, headers: array<string,string>, body: string}|null
     */
    private function pathViolation(mixed $paths): ?array
    {
        $error = self::scanPathsError($this->serveRoot, $paths);
        if ($error === null) {
            return null;
        }
        return $this->json($error['status'], ['error' => $error['error']]);
    }

    /**
     * Shared path-admission check for every analysis entry point.
     *
     * The HTTP layer runs it for both `POST /analyze` and `POST /jobs`;
     * {@see AnalysisWorker} independently re-runs it on dispatched frames
     * with the serve root it was handed at spawn time, so a job that
     * reached the worker through any other route still cannot scan outside
     * the root (defence in depth).
     *
     * Rules: `paths` must be a non-empty array of strings free of NUL bytes.
     * When a serve root is configured, absolute paths and paths containing
     * `..` are rejected, and every path must resolve via realpath() inside
     * the serve root.
     *
     * @return array{status:int, error:string}|null Null when all paths are admissible.
     */
    public static function scanPathsError(?string $serveRoot, mixed $paths): ?array
    {
        if (!is_array($paths) || $paths === []) {
            return ['status' => 400, 'error' => 'paths must be a non-empty array'];
        }

        // Parse don't validate: one pass turns the raw list into trusted strings.
        $checked = [];
        foreach ($paths as $path) {
            if (!is_string($path)) {
                return ['status' => 400, 'error' => 'paths must contain only strings'];
            }
            if (str_contains($path, "\0")) {
                // realpath() raises a ValueError on NUL bytes, which would
                // escape admission as a 500; answer this shape violation
                // with the same clean 400 every other one gets.
                return ['status' => 400, 'error' => 'paths must not contain null bytes'];
            }
            $checked[] = $path;
        }

        if ($serveRoot === null) {
            return null;
        }

        $serveRootResolved = realpath($serveRoot);
        if ($serveRootResolved === false) {
            return ['status' => 500, 'error' => 'serve-root does not exist'];
        }
        // Ensure serveRoot ends with DIRECTORY_SEPARATOR for prefix matching.
        $serveRootResolved .= DIRECTORY_SEPARATOR;

        foreach ($checked as $path) {
            // Reject absolute paths.
            if (str_starts_with($path, '/')) {
                return ['status' => 400, 'error' => 'absolute paths are not allowed: ' . $path];
            }
            // Reject paths containing traversal sequences.
            if (str_contains($path, '..')) {
                return ['status' => 400, 'error' => 'path traversal is not allowed: ' . $path];
            }
            $resolved = realpath($path);
            if ($resolved === false) {
                // File does not exist; still check containment to avoid bypassing
                // via symlinks outside serveRoot.
                return ['status' => 400, 'error' => 'path not found: ' . $path];
            }
            if (!str_starts_with($resolved . DIRECTORY_SEPARATOR, $serveRootResolved)) {
                return ['status' => 400, 'error' => 'path is outside serve-root: ' . $path];
            }
        }
        return null;
    }

    /**
     * Enumerate which refused job-payload fields a payload actually set.
     *
     * Key presence decides, value-independent: an explicit `null` is still an
     * attempt to configure the refused field. The shared list lives in
     * {@see self::REFUSED_PAYLOAD_KEYS} so HTTP admission and the worker's
     * RUN-frame gate refuse exactly the same fields — fail fast, fail loud,
     * and stay auditable if the pipeline ever starts consuming more keys.
     *
     * @param array<string,mixed> $payload
     * @return list<string>
     */
    public static function refusedPayloadKeys(array $payload): array
    {
        $refused = [];
        foreach (self::REFUSED_PAYLOAD_KEYS as $key) {
            if (array_key_exists($key, $payload)) {
                $refused[] = $key;
            }
        }
        return $refused;
    }
}
