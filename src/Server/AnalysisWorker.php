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
use Phpdup\Reporting\Report;
use Phpdup\Reporting\Ranker;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Background worker subprocess that processes analysis jobs from a Unix socket.
 *
 * Wire protocol (all messages newline-terminated):
 *   - Received: RUN <jobId> <configJson>\n
 *   - Sent on success: OK <jobId>\n
 *   - Sent on failure: FAIL <jobId> <error>\n
 *
 * The received line is size-bounded by {@see AnalysisWorker::MAX_REQUEST_BYTES};
 * a frame past the cap answers {@see AnalysisWorker::REQUEST_TOO_LONG_ACK}
 * without the worker ever buffering the endless line whole.
 *
 * The worker is spawned by ServeCommand as
 * `bin/phpdup-worker <socket> <result-dir> [<serve-root>]` and runs the full
 * analysis pipeline using the same stages as the CLI command.
 *
 * RUN-frame admission (enumerated — the frame accepts exactly these fields):
 *   - `<jobId>` (frame token): must match {@see AnalysisWorker::JOB_ID_PATTERN},
 *     the queue's own mint shape (`JobQueue::enqueue()` produces
 *     `bin2hex(random_bytes(8))` — 16 lowercase hex chars). The id names the
 *     result file inside the results directory, so a non-conforming id
 *     (`../..` traversal, NULs, slashes) is refused with an error ack before
 *     anything is written or run — a hand-fed frame can never escape the
 *     results directory. The raw id is never echoed back into the ack either;
 *     refusals answer `FAIL 0 invalid job id` so a crafted id can never split
 *     the line-based wire protocol.
 *   - `paths` (required): non-empty list of relative paths, re-validated by
 *     the worker against its spawn-time serve root via
 *     {@see Application::scanPathsError()} — defence in depth: even a frame
 *     that bypassed HTTP admission cannot scan outside the root.
 *   - `ml_pair_url` (refused): explicit {@see Application::REFUSED_PAYLOAD_KEYS}
 *     gate — the shared list HTTP admission mirrors on `POST /jobs`, so the
 *     field is refused at both layers from one source of truth.
 *     An ML pair-scoring endpoint must never be pointed at a remote by a job
 *     payload; ML-URL admission stays on the CLI/config path behind
 *     MlClient::isAllowedUrl()'s fail-closed validation gate.
 *   - All other keys are ignored: the worker builds its Config solely from
 *     `paths` and never consumes the raw frame array.
 *
 * Before any of that, the read itself is bounded (shell-049): the raw
 * socket line may not exceed {@see AnalysisWorker::MAX_REQUEST_BYTES}
 * bytes — the {@see Application::MAX_BODY_BYTES} HTTP ceiling every
 * admitted frame already respects, plus generous headroom for the
 * `RUN <jobId> ` prefix — so a peer wired straight to the socket can
 * not make the worker buffer a single endless line.
 *
 * A poisoned frame never kills the worker: every per-client handler is
 * wrapped so the loop keeps serving the next dispatch.
 *
 * Lifecycle (shell-016): the worker is a child of `phpdup serve`, and a
 * dead parent must not leave it looping forever. The kernel reparents a
 * child the instant its parent dies, so one poll per loop iteration
 * (bound by the 5 s accept timeout) comparing the current parent pid to
 * the spawn-time one is one detection leg. It is not the only one: the
 * spawn runs through an `sh -c` wrapper, so the worker's direct parent
 * is that shell — a hard-killed daemon reparents the SHELL, not the
 * worker. ServeCommand therefore hands its own pid to the child
 * environment (PHPDUP_SERVE_PID — neither wire nor argv), and the poll
 * also treats `kill(pid, 0)` failure of that pid as daemon death. On
 * either leg the worker closes shop and unlinks its own socket file —
 * the parent's cleanup handler will never run for it. Where both reads
 * are unresolvable (no ext-posix, no env token, spawned under init) the
 * check degrades to a deliberate no-op instead of guessing, the same
 * posture WorkerPool takes without pcntl_fork. No wire change: the
 * checks read only the worker's own process-table view.
 */
final class AnalysisWorker
{
    /**
     * Mint shape of a job id: exactly what {@see JobQueue::enqueue()}
     * produces (`bin2hex(random_bytes(8))` — 16 lowercase hex chars).
     * The worker validates every RUN frame against this before touching
     * the filesystem, so result-file names can never traverse out of
     * the results directory. Public: the queue-mint pin test and any
     * future dispatcher-side validation share this one source of truth.
     */
    public const JOB_ID_PATTERN = '/^[0-9a-f]{16}$/';

    /**
     * Upper bound (bytes) on one RUN frame line as read from a client
     * (shell-049).
     *
     * The config JSON carried by a frame is already capped at
     * {@see Application::MAX_BODY_BYTES} by HTTP admission; this ceiling
     * mirrors it for the worker's own socket read, so a peer connected
     * directly to the worker socket — bypassing the HTTP layer entirely —
     * can never make it buffer an arbitrarily long single line. The
     * 1024-byte headroom over the body ceiling covers the frame overhead
     * (`RUN ` + a 16-hex-char id + the field separator = 21 bytes) many
     * times over, so every frame the HTTP layer admits still passes the
     * worker's read bound untouched.
     *
     * Public so the cap-alignment pin test and any future dispatcher-side
     * check can tie themselves to this one source of truth; spawns that
     * need a different bound use the constructor seam.
     */
    public const MAX_REQUEST_BYTES = Application::MAX_BODY_BYTES + 1024;

    /**
     * Ack answering a frame that tripped the read bound. `FAIL 0` carries
     * no job id — at read time none is known yet, and the raw bytes of an
     * overflowing line must never re-enter the line-based frame (the same
     * reason shell-022 refusals echo no id).
     */
    public const REQUEST_TOO_LONG_ACK = "FAIL 0 request line too long\n";

    /** @var resource|null */
    private $socket;

    private string $socketPath;

    private string $resultDir;

    /** Optional absolute path to which job scanning is confined. */
    private ?string $serveRoot;

    /** Byte bound enforced on one RUN frame line (see {@see self::MAX_REQUEST_BYTES}). */
    private int $maxRequestBytes;

    private bool $running = false;

    /**
     * Test seam for the parent-pid read used by the orphan self-exit
     * (mirrors the ServeCommand ownerProbe pattern): null uses the real
     * `posix_getppid()`; a probe feeds pids so the reparent comparison —
     * which runs unseamed — can be exercised hermetically.
     *
     * @var (callable(): int|null)|null
     */
    public $parentPidProbe = null;

    /** Parent pid captured at run() start; null = unresolvable (check disabled). */
    private ?int $spawnParentPid = null;

    /** Owning daemon's pid from the spawn env (shell-016); null = leg disabled. */
    private ?int $servePid = null;

    /**
     * @param string      $socketPath      Path to the Unix socket to listen on.
     * @param string      $resultDir       Directory where result files are written.
     * @param string|null $serveRoot       Serve root jobs are confined to; null disables
     *                                     confinement (manual/dev spawns only).
     * @param int|null    $maxRequestBytes Override for {@see self::MAX_REQUEST_BYTES};
     *                                     null keeps the production cap. The seam exists
     *                                     so tests can drive the overflow path over a
     *                                     socketpair without pushing megabytes.
     * @param int|null    $servePid        Pid of the owning `phpdup serve` process
     *                                     (shell-016, from the spawn env); null or a
     *                                     non-positive value disables the direct
     *                                     daemon-liveness leg, leaving the reparent
     *                                     belt — the posture of manual dev spawns.
     */
    public function __construct(
        string $socketPath,
        string $resultDir,
        ?string $serveRoot = null,
        ?int $maxRequestBytes = null,
        ?int $servePid = null
    ) {
        if ($maxRequestBytes !== null && $maxRequestBytes < 1) {
            throw new \InvalidArgumentException('maxRequestBytes must be a positive byte count');
        }
        $this->socketPath = $socketPath;
        $this->resultDir = $resultDir;
        $this->serveRoot = $serveRoot;
        $this->maxRequestBytes = $maxRequestBytes ?? self::MAX_REQUEST_BYTES;
        $this->servePid = ($servePid !== null && $servePid > 0) ? $servePid : null;
    }

    /**
     * Run the worker main loop, accepting and processing jobs.
     *
     * Blocks until the socket is closed, an unrecoverable error occurs,
     * or the spawning `phpdup serve` parent dies (shell-016 — checked at
     * the top of every iteration, so one idle accept timeout is the
     * worst-case exit latency).
     */
    public function run(): void
    {
        $this->setupSocket();
        $this->running = true;
        $this->spawnParentPid = $this->currentParentPid();

        while ($this->running) {
            if ($this->parentHasDied()) {
                // The serve parent died (the kernel reparented us the
                // moment it did): quit instead of looping forever as an
                // orphan, and remove the socket file ourselves — the
                // parent's cleanupWorker() can no longer do it.
                $this->running = false;
                $this->abandonOrphanSocket();
                break;
            }
            $client = @stream_socket_accept($this->socket, 5);
            if ($client === false) {
                continue;
            }
            try {
                $this->handleClient($client);
            } catch (\Throwable) {
                // Belt-and-braces: handleClient is self-contained, but a
                // transport-level failure must never take the loop down.
            } finally {
                @fclose($client);
            }
        }
    }

    /**
     * Stop the worker gracefully.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Current parent pid, or null when it cannot be resolved (no
     * ext-posix). The {@see $parentPidProbe} seam overrides the real read
     * only — the comparison in {@see parentHasDied()} stays unseamed.
     */
    private function currentParentPid(): ?int
    {
        $probe = $this->parentPidProbe;
        if ($probe !== null) {
            return $probe();
        }

        return function_exists('posix_getppid') ? posix_getppid() : null;
    }

    /**
     * True once the owning daemon is gone: either its env-carried pid
     * stopped existing, or this process was reparented away from its
     * spawn-time parent.
     *
     * The daemon-pid leg is the one that matters through the real spawn
     * chain (the worker's direct parent is the `sh -c` wrapper, which
     * keeps waiting even after the daemon dies). The reparent leg is the
     * belt: it covers manual/dev spawns (no env token), a wrapper killed
     * out from under the worker, and the pid's death before the OS ever
     * releases it — a ZOMBIE daemon still answers `kill(pid, 0)`, so
     * only the un-reaped-parent window leans on reparenting, and the
     * kernel reparents at death, not at reap, so the two legs together
     * never depend on the reap. Pid reuse in the daemon-pid slot is a
     * bounded-theoretical false-alive at worst: pid_max cycles dwarf any
     * orphan's lifetime, and the reparent leg plus ServeCommand's own
     * bounded tree reap are already in front of it.
     *
     * Fail-open by design: unresolvable reads (no ext-posix), a missing
     * env token, or a worker spawned directly under init all disable the
     * affected leg — looping on is the honest answer, never a
     * speculative self-kill.
     */
    private function parentHasDied(): bool
    {
        $servePid = $this->servePid;
        if ($servePid !== null && function_exists('posix_kill') && !@posix_kill($servePid, 0)) {
            return true;
        }

        $spawn = $this->spawnParentPid;
        if ($spawn === null || $spawn <= 1) {
            return false;
        }

        $current = $this->currentParentPid();

        return $current !== null && $current !== $spawn;
    }

    /**
     * Remove the socket file this worker created when exiting because its
     * parent is gone: the unlink in ServeCommand::cleanupWorker() will
     * never run, so without this the dead daemon's /tmp surface outlives
     * both processes.
     */
    private function abandonOrphanSocket(): void
    {
        if ($this->socketPath !== '' && file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }
    }

    /**
     * Set up the Unix socket server.
     */
    private function setupSocket(): void
    {
        if (file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }

        $this->socket = @stream_socket_server(
            'unix://' . $this->socketPath,
            $errno,
            $errstr
        );
        if ($this->socket === false) {
            throw new \RuntimeException("Failed to create socket at {$this->socketPath}: {$errstr}");
        }

        if (!chmod($this->socketPath, 0600)) {
            throw new \RuntimeException("Failed to chmod socket: {$this->socketPath}");
        }
    }

    /**
     * Handle a connected client, processing one job request.
     *
     * Never throws: any failure is answered with a FAIL frame so the
     * dispatching application (and any future client) gets a deterministic
     * line instead of a dead worker.
     *
     * @param resource $client
     */
    private function handleClient($client): void
    {
        $jobId = '';
        try {
            // shell-049: bounded read. An unbounded fgets let a peer wired
            // straight to the worker socket push a single endless line and
            // have it buffered whole. stream_get_line stops at
            // maxRequestBytes+1 bytes looking for the delimiter, so a frame
            // past the cap is refused from the bound itself — one byte of
            // slack is all that is ever buffered beyond the cap — and the
            // worker answers and moves on to the next dispatch.
            $line = @stream_get_line($client, $this->maxRequestBytes + 1, "\n");
            if ($line === false || $line === '') {
                return;
            }
            if (strlen($line) > $this->maxRequestBytes) {
                @fwrite($client, self::REQUEST_TOO_LONG_ACK);
                return;
            }

            $line = rtrim($line, "\r\n");
            if (!str_starts_with($line, 'RUN ')) {
                return;
            }

            $rest = substr($line, 4);
            $spacePos = strpos($rest, ' ');
            if ($spacePos === false) {
                @fwrite($client, "FAIL 0 malformed request\n");
                return;
            }

            $jobId = substr($rest, 0, $spacePos);
            $configJson = substr($rest, $spacePos + 1);

            // shell-022: the id names `<result-dir>/<id>.json`, so it is
            // checked against the queue's mint shape BEFORE any write or
            // pipeline work. `FAIL 0` answers without echoing the raw id —
            // an unvalidated token must not re-enter the line-based frame.
            if (preg_match(self::JOB_ID_PATTERN, $jobId) !== 1) {
                @fwrite($client, "FAIL 0 invalid job id\n");
                return;
            }

            try {
                $configData = json_decode($configJson, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $this->writeFail($client, $jobId, 'invalid JSON: ' . $e->getMessage());
                return;
            }
            if (!is_array($configData)) {
                // Scalars and null decode fine but are not job frames — reject
                // here instead of letting processJob's array parameter raise a
                // TypeError through the wire.
                $this->writeFail($client, $jobId, 'config JSON must decode to an object');
                return;
            }

            /** @var array<string,mixed> $configData */
            $result = $this->processJob($configData);
            if ($result['status'] === 200) {
                $encoded = json_encode($result['body'], JSON_THROW_ON_ERROR);
                $resultFile = $this->resultDir . '/' . $jobId . '.json';
                if (@file_put_contents($resultFile, $encoded) === false) {
                    $this->writeFail($client, $jobId, 'failed to write result file');
                    return;
                }
                @fwrite($client, "OK {$jobId}\n");
            } else {
                $this->writeFail($client, $jobId, $result['error'] ?? 'job failed');
            }
        } catch (\Throwable $e) {
            // Crash-proof dispatch: a poisoned frame or an unexpected
            // pipeline error fails this job only — the worker stays alive.
            $this->writeFail($client, $jobId, 'worker error: ' . $e->getMessage());
        }
    }

    /**
     * Write a single-line FAIL frame. The error text is stripped of line
     * breaks so a job error can never split the line-based wire protocol.
     *
     * @param resource $client
     */
    private function writeFail($client, string $jobId, string $error): void
    {
        $error = str_replace(["\r", "\n"], ' ', $error);
        @fwrite($client, "FAIL {$jobId} {$error}\n");
    }

    /**
     * Process an analysis job by running the full pipeline.
     *
     * @param array<string,mixed> $configData
     * @return array{status:int, body?:array<string,mixed>, error?:string}
     */
    private function processJob(array $configData): array
    {
        try {
            // Defence in depth (once-001): the worker re-validates the paths
            // against its spawn-time serve root, so a job that bypassed HTTP
            // admission (or an operator feeding the socket by hand) still
            // cannot scan outside the confinement boundary.
            $violation = Application::scanPathsError($this->serveRoot, $configData['paths'] ?? null);
            if ($violation !== null) {
                return ['status' => $violation['status'], 'error' => $violation['error']];
            }

            // RUN-frame admission: refuse config fields that must never enter
            // through the wire (see class docblock for the full enumeration;
            // the list itself is the one HTTP `POST /jobs` admission shares).
            $refused = Application::refusedPayloadKeys($configData);
            if ($refused !== []) {
                return ['status' => 400, 'error' => 'RUN frame must not set: ' . implode(', ', $refused)];
            }

            /** @var list<string> $paths */
            $paths = $configData['paths'];
            $config = Config::defaults($paths);
            $state = new PipelineState($config);
            $out = new NullOutput();

            (new ScanningStage())->run($state, $out);
            (new PreprocessStage(useCache: false))->run($state, $out);
            (new ClusterStage(exactOnly: true, useClusterCache: false))->run($state, $out);
            (new RefactorStage(useCache: false))->run($state, $out);

            $report = new Report(
                files: count($state->files),
                blocks: count($state->blocks),
                parseErrors: $state->parseErrors,
                clusters: (new Ranker($config->minClusterImpact))->rank($state->clusters),
                config: $config,
            );

            $body = (new JsonReporter())->build($report);
            return ['status' => 200, 'body' => $body];
        } catch (\Throwable $e) {
            return ['status' => 500, 'error' => $e->getMessage()];
        }
    }
}
