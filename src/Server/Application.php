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
 * timeout is no longer mistaken for a dispatch failure. A frame that is
 * fully written to a live-but-busy worker (it answers nothing within
 * {@see self::DISPATCH_ACK_TIMEOUT_SECONDS} because it is still running an
 * earlier job) is likewise an admission, not a failure: the request
 * returns 202 and the row honestly stays `pending` (once-002: no false
 * 500 while the worker is alive). The dispatch connection is held open
 * after the exchange, and every status/result read asks it for the
 * worker's terminal event — a late `OK` (buffered admission now running),
 * an `ERR`/`FAIL` frame (failed with the worker's own reason), or the
 * close itself ("the worker ended without publishing a result") — so
 * worker death is an observed fact reported immediately, not a timeout
 * inference. Completion is still primarily the result file appearing:
 * every read first reconciles the queue row against the results
 * directory, transitioning the job to completed the moment its result
 * file is present. The {@see self::ASYNC_RESULT_DEADLINE_SECONDS}
 * absence-deadline remains the backstop for channels that stay open yet
 * silent (a wedged worker). Without a worker socket, analysis runs
 * synchronously (sync mode for dev/demo).
 *
 * Queue-capacity admissions (once-002): at {@see JobQueue::MAX_JOBS}
 * the queue evicts the oldest row (terminal first, then pending, then
 * running) to admit the new job; the dispatch response body carries an
 * `evicted` list naming the displaced ids, and polling an evicted id
 * answers 404 with the eviction note instead of a bare "job not found".
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
 *   The optional `exact_only` payload field is shape-gated identically
 *   ({@see self::exactOnlyViolation()}) and, once admitted, selects the
 *   clustering posture on BOTH transports ({@see self::exactOnlyPosture()})
 *   — a sync run and an async job over one payload are the same report,
 *   never a route-dependent coercion (once-065).
 *   In async mode the worker independently re-runs all three gates
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
     * identical gate to every RUN frame ({@see AnalysisWorker::admitFrame()}).
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
     * Absence-deadline backstop for an async job's result file (shell-032,
     * lane choice pinned: deadline over relying solely on an ERR frame).
     *
     * After the worker's accept-ack the server never blocks on the
     * analysis; completion is the result file appearing. A worker that
     * crashes mid-job leaves no file — its held dispatch channel reports
     * the death (EOF) or failure (ERR frame) on the very next status read,
     * so a dead worker is no longer a timeout story. This window only
     * backstops channels that stay open yet silent (a wedged worker) and
     * rows whose channel was never observable (e.g. dispatched before the
     * app could keep it): a still-live row whose result has not shown up
     * within it is honestly failed. 300 s is generous enough that a
     * slow-but-successful analysis on a large fixture corpus is never
     * mistaken for a dead worker, yet bounded so a wedged one does not
     * linger as "running" forever. Tests shrink it via
     * {@see self::$asyncResultDeadlineSeconds}.
     */
    public const ASYNC_RESULT_DEADLINE_SECONDS = 300;

    /**
     * Bound on how long the dispatcher waits for the worker's ACCEPT-ack
     * (shell-032). The ack is written the instant the frame is admitted, so
     * this bounds a wedged socket — not the analysis. A second or two is
     * ample; the whole point of the change is that this number no longer
     * caps how long a job may run. As of once-002 it does not decide
     * failure either: a frame fully written to a live listener that has not
     * answered inside this window is a BUFFERED admission (the worker is
     * busy behind an earlier job), not a dispatch failure — only the
     * exchange itself being unable to complete (connect refused, short
     * write, hang-up without any reply) rejects. Tests shrink it via
     * {@see self::$dispatchAckTimeoutSeconds}.
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

    /**
     * Test seam for the accept-ack window in seconds (once-002, mirrors
     * {@see $asyncResultDeadlineSeconds}): null uses
     * {@see self::DISPATCH_ACK_TIMEOUT_SECONDS}. Only the number is seamed;
     * the buffered-vs-rejected verdict it feeds runs unseamed, so units can
     * drive the real socket legs (held channel, EOF observation) without a
     * wall-clock penalty.
     */
    public ?int $dispatchAckTimeoutSeconds = null;

    /**
     * Test seam for the held dispatch channel's follow-up event (once-002):
     * null reads the real channel registered by {@see exchangeAck()}; a
     * probe feeds the verdict (`['kind' => 'ack'|'still'|'fail'|'gone',
     * 'reason' => string]`, null = no event source) so the buffered→running
     * transition, the ERR/gone failure legs and their queue writes run
     * unseamed — only which event the kernel socket would show is replaced.
     *
     * @var (callable(string, string): (?array{kind:string, reason?:string}))|null
     */
    public $workerChannelProbe = null;

    /** @var array<string, float> dispatch timestamp per async job id, for the absence-deadline. */
    private array $asyncDispatchedAt = [];

    /**
     * @var array<string, resource> kept-open dispatch channels per live
     *      async job (once-002): after the frame is fully written the
     *      connection stays registered non-blocking, so status reads can
     *      observe the worker's terminal frames (OK/ERR/FAIL) or its close.
     */
    private array $asyncChannels = [];

    /** Three-way dispatch verdict for {@see dispatchToWorker()}. */
    private const DISPATCH_ACCEPTED = 'accepted';
    private const DISPATCH_BUFFERED = 'buffered';
    private const DISPATCH_REJECTED = 'rejected';

    /** Channel event kinds for {@see asyncChannelEvent()}. */
    private const CHANNEL_ACK   = 'ack';   // a late accept-ack: buffered admission now running
    private const CHANNEL_STILL = 'still'; // open, nothing readable: the worker is on it
    private const CHANNEL_FAIL  = 'fail';  // ERR/FAIL frame: failed with the worker's reason
    private const CHANNEL_GONE  = 'gone';  // closed without a frame or a result: worker died
    private const CHANNEL_NONE  = 'none';  // no channel for this job (seamed/dev posture)

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
                return $this->json(404, ['error' => $this->jobGoneReason($m[1])]);
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
                return $this->json(404, ['error' => $this->jobGoneReason($m[1])]);
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
        // once-065: the clustering posture comes from the payload, never a
        // hardcoded force — the same gate+read the worker runs on the frame,
        // so sync and async answers to one payload are the same report.
        if (($violation = self::exactOnlyViolation($payload)) !== null) {
            return $this->json($violation['status'], ['error' => $violation['error']]);
        }
        /** @var list<string> $paths */
        $paths = $payload['paths'];
        try {
            $config = $this->buildAnalyzeConfig($paths);
            $state = new PipelineState($config);
            $out   = new NullOutput();
            (new ScanningStage())->run($state, $out);
            (new PreprocessStage(useCache: $this->useCache))->run($state, $out);
            (new ClusterStage(exactOnly: self::exactOnlyPosture($payload), useClusterCache: $this->useCache))->run($state, $out);
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
     * Extract the job-row summary from a decoded JsonReporter payload.
     *
     * The counters live in the payload's nested `summary` block
     * ({@see JsonReporter::build()}) — at the root, `files` and `blocks`
     * do not exist at all and `clusters` is the full list of cluster
     * payloads. Reading the root level was the historic
     * php-dup-finder-once-003 bug: every stored summary came back
     * `{0,0,0}` no matter what the analysis found. Both settlement paths
     * — the worker-less `/jobs` ack and the async result-file reconcile —
     * pass through this one helper, so a queue row shows exactly the
     * counters `--json` reports. A mistyped or missing per-key value
     * degrades to an honest zero, never a guess. As a boundary leniency the
     * reader also accepts the legacy FLAT shape (counters at the root, no
     * `summary` block at all) — the shape hand-written settle fakes carry —
     * so a payload parses under either layout; real JsonReporter bytes
     * always nest, and the root-level `clusters` LIST can never leak into
     * the counter read because every leg is `is_int`-gated.
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
        $summary = is_array($decoded['summary'] ?? null) ? $decoded['summary'] : $decoded;
        return [
            'files'    => is_int($summary['files'] ?? null) ? $summary['files'] : 0,
            'blocks'   => is_int($summary['blocks'] ?? null) ? $summary['blocks'] : 0,
            'clusters' => is_int($summary['clusters'] ?? null) ? $summary['clusters'] : 0,
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

        // once-065: the optional posture key is shape-gated with the same
        // 400-before-mint discipline, at both layers (the worker re-checks
        // the RUN frame identically), so a mistyped `exact_only` never
        // becomes a queued row and never silently clusters the wrong way.
        if (($violation = self::exactOnlyViolation($payload)) !== null) {
            return $this->json($violation['status'], ['error' => $violation['error']]);
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
            $evicted = $this->absorbQueueEvictions();

            $verdict = $this->dispatchToWorker($id, $configJson);
            if ($verdict === self::DISPATCH_REJECTED) {
                $this->queue->markFailed($id, 'failed to dispatch to worker');
                return $this->json(500, $this->withEvictions(
                    ['error' => 'failed to dispatch job to worker'],
                    $evicted
                ));
            }
            if ($verdict === self::DISPATCH_ACCEPTED) {
                // shell-032: the worker's ACCEPT-ack (`OK <id>` written before
                // it runs the pipeline) proves the frame was admitted — it
                // does NOT mean the analysis finished. The job is now running
                // in the background process; its terminal state arrives on
                // later reads via the held channel or the result file, so
                // this request returns 202 without ever blocking on the work.
                $this->queue->markRunning($id);
            }
            // DISPATCH_BUFFERED (once-002): the frame is fully written into a
            // live listener the worker has not reached yet (it is busy behind
            // an earlier job). That is an admission, not a failure — the row
            // honestly stays pending, and the channel reports the worker's
            // OK/FAIL verdict once it reads the frame. Either way record the
            // dispatch instant that anchors the absence-deadline backstop.
            $this->asyncDispatchedAt[$id] = $this->asyncNow();
            return $this->json(202, $this->withEvictions(['job_id' => $id], $evicted));
        }

        // Sync mode (no worker): run analysis inline
        $id = $this->queue->enqueue($payload);
        $evicted = $this->absorbQueueEvictions();
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
        return $this->json(202, $this->withEvictions(['job_id' => $id], $evicted));
    }

    /**
     * Dispatch a job to the background worker via Unix socket.
     *
     * Wire protocol: `RUN <jobId> <configJson>\n` in; the worker answers
     * `OK <jobId>\n` as an ACCEPT-ack the instant it admits the frame
     * (shell-032) — before running the pipeline. This method therefore
     * resolves as soon as the frame's fate is decided: it never waits for
     * (or false-fails) a job that simply takes a while. As of once-002 the
     * verdict is three-way: an immediate accept, a BUFFERED admission
     * (frame fully written to a live listener too busy to answer inside the
     * ack window — an honest 202, row stays pending, the held channel
     * carries the late verdict), or a genuine dispatch rejection (connect
     * refused, short write, hang-up without any reply).
     *
     * @return self::DISPATCH_ACCEPTED|self::DISPATCH_BUFFERED|self::DISPATCH_REJECTED
     */
    private function dispatchToWorker(string $jobId, string $configJson): string
    {
        if ($this->workerSocket === null) {
            return self::DISPATCH_REJECTED;
        }

        $ack = $this->exchangeAck($jobId, "RUN {$jobId} {$configJson}\n");
        if ($ack === '') {
            return self::DISPATCH_BUFFERED;
        }

        return is_string($ack) && str_starts_with($ack, 'OK ' . $jobId)
            ? self::DISPATCH_ACCEPTED
            : self::DISPATCH_REJECTED;
    }

    /**
     * Write a RUN frame and read the worker's single first reply line,
     * KEEPING THE CONNECTION OPEN afterwards (once-002): the held channel
     * is the live job's terminal-event source — the worker's ERR frame,
     * the late OK/FAIL for a buffered admission, or the close itself once
     * the worker is gone (see {@see asyncChannelEvent()}).
     *
     * Returns the trimmed first line; `''` when the frame was fully written
     * to a live listener that answered nothing inside
     * {@see self::DISPATCH_ACK_TIMEOUT_SECONDS} (the worker is busy behind
     * an earlier job — an admission, not a failure); and null only when the
     * exchange itself failed: unreachable socket, short write, or the peer
     * hung up without answering a byte.
     */
    private function exchangeAck(string $jobId, string $message): ?string
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

        $written = @fwrite($socket, $message);
        if ($written === false || $written !== strlen($message)) {
            @fclose($socket);
            return null;
        }

        stream_set_timeout($socket, $this->dispatchAckTimeoutSeconds ?? self::DISPATCH_ACK_TIMEOUT_SECONDS);
        $response = @fgets($socket);
        $noLine   = $response === false || $response === '';
        if ($noLine && stream_get_meta_data($socket)['timed_out'] !== true) {
            // EOF (or stream error) without a reply: the worker hung up
            // before admitting anything — a genuine dispatch failure.
            @fclose($socket);
            return null;
        }

        // The frame is fully written into a live socket — answered now or
        // queued at a busy worker. Keep the channel, non-blocking, for the
        // terminal-event reads on later status requests.
        @stream_set_blocking($socket, false);
        $this->asyncChannels[$jobId] = $socket;

        return $noLine ? '' : trim($response);
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
     * (shell-032: completion is the result file appearing) and its held
     * dispatch channel (once-002: the worker's own terminal report — a late
     * accept-ack, an ERR/FAIL frame, or the close of a dead worker — is an
     * observed fact, not a timeout inference).
     *
     * A pending/running job whose result file has landed is acked completed
     * (reduced to the retained summary, exactly as the sync path does); the
     * file is checked FIRST so a worker that published and then closed its
     * channel never mis-reports as "gone". Otherwise the channel event
     * transitions the row: a late `OK` moves pending→running, an `ERR`/
     * `FAIL` frame fails it with the worker's reason, and a hang-up without
     * either fails it honestly as worker death. A row this process never
     * dispatched and whose channel says nothing falls back to the
     * ASYNC_RESULT_DEADLINE_SECONDS absence-deadline (the wedge backstop).
     * Already-terminal rows are never touched, and the method is a no-op in
     * sync mode (the inline run settled the row).
     */
    private function reconcileAsyncResult(string $jobId): void
    {
        if ($this->workerSocket === null || $this->resultDir === null) {
            return;
        }

        $job = $this->queue->get($jobId);
        if ($job === null) {
            // Row gone (capacity-evicted or TTL-purged): drop its local
            // async state so channels and timestamps never leak.
            $this->forgetAsyncJob($jobId);
            return;
        }
        $status = $job['status'];
        if ($status !== JobQueue::STATUS_PENDING && $status !== JobQueue::STATUS_RUNNING) {
            $this->forgetAsyncJob($jobId);
            return;
        }

        $resultFile = $this->resultDir . '/' . $jobId . '.json';
        if (is_file($resultFile)) {
            $this->forgetAsyncJob($jobId);
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

        // No file yet: ask the held dispatch channel what the worker said.
        $event = $this->asyncChannelEvent($jobId);
        if ($event['kind'] === self::CHANNEL_ACK) {
            // A buffered admission the worker has now read and accepted.
            if ($status === JobQueue::STATUS_PENDING) {
                $this->queue->markRunning($jobId);
            }
            // An ack is evidence of life: it ends this reconcile. Falling
            // through would let the absence-backstop below false-fail a
            // just-acked worker when the first observation lands past the
            // window (deep backlog). asyncDispatchedAt is deliberately not
            // re-anchored here — a channel that goes silent again still
            // settles past the deadline on the next reconcile.
            return;
        } elseif ($event['kind'] === self::CHANNEL_FAIL) {
            $this->forgetAsyncJob($jobId);
            $this->queue->markFailed($jobId, $event['reason'] ?? 'worker reported a failure');
            return;
        } elseif ($event['kind'] === self::CHANNEL_GONE) {
            // Observed fact: the worker closed the channel and left no
            // result — it died (or hung up) with this job unsettled.
            $this->forgetAsyncJob($jobId);
            $this->queue->markFailed($jobId, 'worker ended without publishing a result for this job');
            return;
        }

        $dispatchedAt = $this->asyncDispatchedAt[$jobId] ?? null;
        if ($dispatchedAt === null) {
            return; // not dispatched by this process — the worker owns it
        }
        $deadline = $this->asyncResultDeadlineSeconds ?? self::ASYNC_RESULT_DEADLINE_SECONDS;
        if ($this->asyncNow() - $dispatchedAt > $deadline) {
            $this->forgetAsyncJob($jobId);
            $this->queue->markFailed($jobId, 'worker did not produce a result within ' . $deadline . 's');
        }
    }

    /**
     * The held dispatch channel's verdict for one live async job
     * (once-002): non-blocking read of the connection kept open by
     * {@see exchangeAck()}. `still` means the channel is open with nothing
     * readable (the worker is on the job), `gone` that the peer hung up
     * without publishing (checked after the result-file leg, so a
     * success-close never reads as death), `ack` a late accept-ack, and
     * `fail` an ERR/FAIL frame carrying the worker's own reason. `none` is
     * the no-channel posture (seamed units, rows predating this process).
     *
     * @return array{kind:string, reason?:string}
     */
    private function asyncChannelEvent(string $jobId): array
    {
        $probe = $this->workerChannelProbe;
        if ($probe !== null) {
            return $probe($jobId, $this->workerSocket ?? '') ?? ['kind' => self::CHANNEL_NONE];
        }

        $channel = $this->asyncChannels[$jobId] ?? null;
        if ($channel === null || !is_resource($channel)) {
            return ['kind' => self::CHANNEL_NONE];
        }

        $line = @fgets($channel); // non-blocking: false on EAGAIN
        if (!is_string($line) || $line === '') {
            return feof($channel) ? ['kind' => self::CHANNEL_GONE] : ['kind' => self::CHANNEL_STILL];
        }

        $line = rtrim($line, "\r\n");
        if (str_starts_with($line, 'OK ' . $jobId)) {
            return ['kind' => self::CHANNEL_ACK];
        }
        $prefixLen = 0;
        if (str_starts_with($line, 'ERR ')) {
            $prefixLen = 4;
        } elseif (str_starts_with($line, 'FAIL ')) {
            $prefixLen = 5;
        }
        if ($prefixLen !== 0) {
            $rest      = substr($line, $prefixLen);
            $nextSpace = strpos($rest, ' ');
            $frameId   = $nextSpace === false ? $rest : substr($rest, 0, $nextSpace);
            if ($frameId !== $jobId) {
                // Protocol breach: a terminal frame naming a different job —
                // this row's channel is untrustworthy; fail it, don't trust it.
                return ['kind' => self::CHANNEL_FAIL, 'reason' => 'worker sent a terminal frame naming a different job'];
            }
            $prefix  = $prefixLen === 4 ? 'worker reported failure: ' : 'worker refused frame: ';
            $message = $nextSpace === false ? '' : trim(substr($rest, $nextSpace + 1));
            return ['kind' => self::CHANNEL_FAIL, 'reason' => $prefix . ($message === '' ? 'no reason given' : $message)];
        }

        return ['kind' => self::CHANNEL_STILL];
    }

    /**
     * Consume the capacity evictions the queue just recorded during an
     * enqueue (once-002): their rows are gone, so the process-local async
     * bookkeeping (dispatch timestamp + held channel) must not linger
     * behind them, and the submitting client gets the visible notes.
     *
     * @return list<array{job_id:string, status:string, reason:string}>
     */
    private function absorbQueueEvictions(): array
    {
        $evicted = $this->queue->lastEvictions();
        foreach ($evicted as $note) {
            $this->forgetAsyncJob($note['job_id']);
        }
        return $evicted;
    }

    /**
     * Drop every piece of process-local async state for one job id
     * (idempotent): the dispatch timestamp and the held channel.
     */
    private function forgetAsyncJob(string $jobId): void
    {
        unset($this->asyncDispatchedAt[$jobId]);
        $channel = $this->asyncChannels[$jobId] ?? null;
        unset($this->asyncChannels[$jobId]);
        if (is_resource($channel)) {
            @fclose($channel);
        }
    }

    /**
     * Explain a missing job row (once-002): a capacity eviction names
     * itself; anything else keeps the plain not-found story.
     */
    private function jobGoneReason(string $jobId): string
    {
        return $this->queue->evictionNote($jobId) ?? 'job not found';
    }

    /**
     * Attach the visible cap-eviction notes to a /jobs dispatch body: the
     * ids this submission displaced, with the status each held when it was
     * dropped. Key absent (byte-identical body) when nothing was evicted.
     *
     * @param array<string,mixed>                                     $body
     * @param list<array{job_id:string, status:string, reason:string}> $evicted
     * @return array<string,mixed>
     */
    private function withEvictions(array $body, array $evicted): array
    {
        if ($evicted !== []) {
            $body['evicted'] = $evicted;
        }
        return $body;
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

    /**
     * Shape gate for the optional `exact_only` job-payload field (once-065).
     *
     * The key is admitted only as a real boolean — absent means the
     * default full analysis. Presence decides, value-typed: an explicit
     * `null` is a shape violation too (the same value-independent
     * discipline {@see self::refusedPayloadKeys()} applies to refused
     * keys), so a typo'd posture option can never silently mean the
     * opposite of what the client wrote. Shared by the HTTP admission
     * (400 before any job is minted, for `/analyze` and `/jobs` alike)
     * and the worker's RUN-frame gate, so both layers refuse identically.
     *
     * @param array<string,mixed> $payload
     * @return array{status:int, error:string}|null Null when admissible.
     */
    public static function exactOnlyViolation(array $payload): ?array
    {
        if (!array_key_exists('exact_only', $payload)) {
            return null;
        }
        if (is_bool($payload['exact_only'])) {
            return null;
        }
        return ['status' => 400, 'error' => 'exact_only must be a boolean'];
    }

    /**
     * Trusted clustering posture parsed from an admitted payload
     * (once-065): false (absent or explicit) = the full near-duplicate
     * analysis every reporter surface promises; true = the CLI's
     * `--exact-only` short-circuit. After {@see self::exactOnlyViolation()}
     * has passed, the only values the key can hold are bool or absent —
     * the read below is a parse of trusted data, not a re-check.
     *
     * @param array<string,mixed> $payload
     */
    public static function exactOnlyPosture(array $payload): bool
    {
        return ($payload['exact_only'] ?? false) === true;
    }
}
