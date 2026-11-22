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
 *   - Sent the moment the frame is admitted (BEFORE the job runs):
 *     OK <jobId>\n — the ACCEPT-ack (shell-032). It reports admission,
 *     not completion; the job is still about to run.
 *   - Sent when the frame cannot be admitted at all (bad id, oversize,
 *     malformed/invalid JSON, refused keys, path escape, mistyped
 *     `exact_only`):
 *     FAIL <jobId|0> <error>\n — no accept-ack, nothing written or run.
 *   - Sent when a job is admitted and accepted but then fails during the
 *     pipeline or while publishing its result:
 *     ERR <jobId> <error>\n — advisory; the dispatcher that already read
 *     the accept-ack closes the socket, so a real server learns of this
 *     failure from the result file's absence past its deadline, not from
 *     this frame (the socketpair unit harness reads it because it drives
 *     the handler inline).
 *
 * Completion is signalled by the result file `<result-dir>/<jobId>.json`
 * ATOMICALLY appearing (temp write + size-verified rename), never by a
 * post-work socket line — the historic bug this leg fixes was the server
 * blocking on an after-the-fact `OK` and false-failing any job slower than
 * its ack timeout while the worker silently produced the result anyway.
 * Since once-S46 the appearance itself is trustworthy: the temp must have
 * received every byte (write count AND on-disk size) before the rename, so
 * a partial stream can never publish a partial result as truth, and the
 * incoming RUN frame is only complete once its newline sentinel was
 * consumed — a buffer ending at the peer's EOF is refused loudly with its
 * byte count, never silently run or silently dropped (php-dup-finder-once-050).
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
 *   - `exact_only` (optional bool, once-065): the clustering posture key
 *     `POST /analyze`/`POST /jobs` admit at the HTTP layer — shape-gated by
 *     the shared {@see Application::exactOnlyViolation()} (a present
 *     non-boolean refuses the frame with FAIL, never a silent coercion) and
 *     consumed via {@see Application::exactOnlyPosture()}: absent or false
 *     runs the full near-duplicate analysis (the report every transport
 *     promises), true short-circuits to exact hash matches like the CLI's
 *     `--exact-only`. Both transports read it from the same payload bytes,
 *     so sync and async results never differ by route.
 *   - All other keys are ignored: the worker builds its run solely from
 *     `paths` plus the optional `exact_only` posture and never consumes the
 *     raw frame array any further.
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
 *
 * Death paths (once-S44, php-dup-finder-once-048) tighten that lifecycle
 * into one funnel: run()'s loop ends via the watchdog above, via a
 * stop() (the bin entry's installed SIGINT/SIGTERM handlers turn a
 * targeted signal into a clean stop), or via an escaping throw — and
 * EVERY such exit removes this worker's socket file ({@see run()}'s
 * finally). The only death no process can clean up is SIGKILL/OOM, and
 * that leftover is classified, never trusted: setupSocket() probes a
 * pre-existing name before touching it — live listener → refuse the
 * steal loudly, stale file → reclaim and bind (the Linux EADDRINUSE
 * bind-error path that makes restart-after-kill work). ServeCommand's
 * results-directory pid file (flock, {@see \Phpdup\Cli\ServeCommand::
 * SERVE_PID_FILE}) is the daemon-side twin of the same doctrine.
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

    /**
     * Connect budget (seconds) for {@see self::socketHasLiveListener()}.
     * Same order as the dispatcher's connect timeout in Application
     * (`stream_socket_client(…, 1)`): a live unix listener answers a
     * connect immediately, so the whole window is spent waiting for a
     * verdict that only a wedged kernel socket could delay.
     */
    private const LISTENER_PROBE_TIMEOUT_SECONDS = 1;

    /**
     * Is a live process listening on this unix-socket path?
     *
     * once-S44 (php-dup-finder-once-048): the only question a reclaim
     * decision depends on. A stale leftover (owner died without cleanup)
     * refuses the connect with ECONNREFUSED; a live listener accepts it.
     * The probe sends nothing and closes immediately — the worker's
     * documented silent-probe posture (an empty connection names no job,
     * see {@see self::handleClient()}) makes it harmless against a live
     * worker, idle or mid-job (a busy worker simply finds a closed
     * zero-byte connection in its accept queue once the job lands).
     *
     * Public: ServeCommand's pre-spawn reclaim and any future stale-path
     * audit ride this ONE verdict instead of re-forking a second liveness
     * semantics (the once-S46 taxonomy law, applied to the lifecycle leg).
     */
    public static function socketHasLiveListener(string $path): bool
    {
        $errno = 0;
        $errstr = '';
        $probe = @stream_socket_client(
            'unix://' . $path,
            $errno,
            $errstr,
            self::LISTENER_PROBE_TIMEOUT_SECONDS
        );
        if ($probe === false) {
            // ECONNREFUSED / ENOENT / timeout: nothing live owns the name.
            return false;
        }
        @fclose($probe);
        return true;
    }

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

    /**
     * Test seam for the result-file write (once-S46): a
     * `callable(string $tmpPath, string $bytes): int|false` replacing the
     * temp-write leg, so the failure STATUS arms (false / short count /
     * lying full count on a short file) are drivable hermetically without
     * exhausting a disk. Mirrors the {@see $parentPidProbe} pattern:
     * production never installs it (null = the real writer).
     *
     * @var (callable(string, string): int|false)|null
     */
    public $resultWriter = null;

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

        try {
            $this->running = true;
            $this->spawnParentPid = $this->currentParentPid();

            while ($this->running) {
                if ($this->parentHasDied()) {
                    // The serve parent died (the kernel reparented us the
                    // moment it did): quit instead of looping forever as
                    // an orphan — the socket file goes with the exit, the
                    // parent's cleanupWorker() can no longer remove it.
                    $this->running = false;
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
        } finally {
            // once-S44 (php-dup-finder-once-048): ONE exit funnel for every
            // loop-completing death path. Whatever ended the loop — the
            // orphan watchdog above, a stop() (the bin entry's SIGINT/
            // SIGTERM handlers, or any harness calling stop()), or a
            // throw escaping an iteration — the socket file never
            // outlives the only process that can answer it. A SIGKILL'd
            // worker reaches no funnel (no handler can run); that
            // leftover is what setupSocket()'s probe-then-reclaim leg
            // classifies on the next bind, never silently stolen from a
            // live listener.
            $this->releaseSocket();
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
     * Remove the socket file this worker created, at the single exit
     * funnel of {@see run()} (once-S44): whichever path ended the loop,
     * the parent's cleanup unlink may never run for this file — leaving
     * it means the next pid-reusing serve false-reads it as a ready
     * worker, and no /tmp sweep knows this name.
     */
    private function releaseSocket(): void
    {
        if ($this->socketPath !== '' && file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }
    }

    /**
     * Set up the Unix socket server.
     *
     * once-S44 (php-dup-finder-once-048): the historic posture was an
     * unconditional unlink-then-bind. That silently STEALS a path a live
     * worker is still listening on — the split-brain the finding names:
     * the old holder keeps serving the now-unlinked socket forever while
     * the daemon dispatches to its replacement — and it never proved the
     * removed file was actually dead. Now the existing name is classified
     * first: a live listener refuses this worker's start loudly (the
     * daemon answers with its sync-mode warning instead of a corrupted
     * async posture); a proven-dead leftover — the normal shape after
     * `kill -9`, where no cleanup could run — is reclaimed, then bound.
     * The reclaim is not decoration: on Linux a bind to a path whose name
     * exists fails with EADDRINUSE even when the file is stale, so
     * restart-after-kill works exactly because this leg removes what the
     * probe proves dead. A bind that still fails after the reclaim is
     * reported verbatim (fail-loud, no second silent-retry posture).
     */
    private function setupSocket(): void
    {
        if (file_exists($this->socketPath)) {
            if (self::socketHasLiveListener($this->socketPath)) {
                throw new \RuntimeException(
                    "A live worker is already listening on {$this->socketPath}; refusing to steal its socket"
                );
            }
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
            // shell-049 read bound + once-S46 (php-dup-finder-once-050)
            // sentinel discipline, in one explicit scan. An unbounded fgets
            // once let a peer wired straight to the worker socket push a
            // single endless line and have it buffered whole; the cap here
            // still refuses past MAX_REQUEST_BYTES with one byte of slack.
            // stream_get_line cannot host the sentinel check itself: it
            // also stops at the peer's EOF without consuming the delimiter,
            // and its stream feof state cannot tell "delimiter consumed,
            // peer then died" from "bytes ran out mid-frame" (a completed
            // frame from an already-shutdown sender reads back flagged
            // EOF). So the frame is accumulated chunk-wise and is COMPLETE
            // ONLY ONCE the newline is actually observed. The three early
            // exits mirror the finding's shapes:
            //   - past-cap without sentinel  → REQUEST_TOO_LONG_ACK leg;
            //   - non-empty without sentinel → loud partial-stream refusal
            //     carrying the byte marker (the historic silent-0 trust: a
            //     frame complete but for its `\n` parsed fine, was admitted
            //     and RUN, and a dying dispatcher saw nothing back);
            //   - empty stream / read error  → deliberate silent probe
            //     posture (a connection carrying no bytes names no job).
            $buffer = '';
            $terminated = false;
            while (strlen($buffer) <= $this->maxRequestBytes) {
                $chunk = @fread($client, min(4096, $this->maxRequestBytes + 1 - strlen($buffer)));
                if ($chunk === false || $chunk === '') {
                    break; // peer closed or read error: the sentinel never arrived
                }
                $buffer .= $chunk;
                $newline = strpos($buffer, "\n");
                if ($newline !== false) {
                    $terminated = true;
                    $buffer = substr($buffer, 0, $newline);
                    break;
                }
            }
            if (!$terminated) {
                if (strlen($buffer) > $this->maxRequestBytes) {
                    @fwrite($client, self::REQUEST_TOO_LONG_ACK);
                } elseif ($buffer !== '') {
                    // shell-022 discipline: `FAIL 0` echoes no unvalidated token.
                    @fwrite(
                        $client,
                        'FAIL 0 incomplete RUN frame: stream ended after ' . strlen($buffer)
                            . ' bytes without the newline terminator' . "\n"
                    );
                }
                return;
            }

            $line = rtrim($buffer, "\r\n");
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
                // here instead of letting admitFrame's array parameter raise a
                // TypeError through the wire.
                $this->writeFail($client, $jobId, 'config JSON must decode to an object');
                return;
            }

            /** @var array<string,mixed> $configData */
            // shell-032: admission and execution are split. Everything that
            // can be decided BEFORE running the pipeline — path confinement
            // against the spawn-time serve root, and the refused RUN-frame
            // keys — is resolved first and answered with a FAIL frame, so a
            // frame the worker would reject never gets a misleading accept.
            $admission = $this->admitFrame($configData);
            if ($admission !== null) {
                $this->writeFail($client, $jobId, $admission['error'] ?? 'frame refused');
                return;
            }

            // Admitted: answer the ACCEPT-ack immediately, before running the
            // job. This is the whole fix — the dispatcher reads this line and
            // returns 202 without blocking on the analysis, so a job that
            // takes longer than any socket timeout is no longer mistaken for
            // a dispatch failure. Completion is the result file, not a second
            // ack.
            @fwrite($client, "OK {$jobId}\n");

            $result = $this->executeJob($configData);
            if ($result['status'] !== 200) {
                $this->writeErr($client, $jobId, $result['error'] ?? 'job failed');
                return;
            }
            try {
                $encoded = json_encode($result['body'], JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $this->writeErr($client, $jobId, 'failed to encode result: ' . $e->getMessage());
                return;
            }
            $publishError = $this->publishResult($jobId, $encoded);
            if ($publishError !== null) {
                // Accepted and ran, but the result never landed IN FULL. The
                // server fails it via the result-file absence-deadline; the
                // ERR frame is the socket-visible reason (with the partial
                // byte marker) for a reader that kept the connection open
                // (the unit harness).
                $this->writeErr($client, $jobId, $publishError);
            }
            // Success is the atomic appearance of `<id>.json`; no post-work
            // line is written, because the dispatcher has already left.
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
     * Write a single-line ERR frame — the post-accept failure signal (the
     * frame was admitted and `OK` acked, then the pipeline or the result
     * publish failed). Same newline-strip discipline as
     * {@see self::writeFail()}. Best-effort: a dispatcher that already read
     * the accept-ack and closed the socket simply never sees it; the
     * authoritative signal there is the result file's absence (deadline),
     * not this line.
     *
     * @param resource $client
     */
    private function writeErr($client, string $jobId, string $error): void
    {
        $error = str_replace(["\r", "\n"], ' ', $error);
        @fwrite($client, "ERR {$jobId} {$error}\n");
    }

    /**
     * Publish a job's result atomically (shell-032) and only after the write
     * status vouches for EVERY byte (once-S46, php-dup-finder-once-050):
     * write to a sibling temp file, verify it landed whole, then rename. A
     * rename within one directory is atomic, so the server-side reconcile —
     * which treats "the file is present" as completion — can never observe a
     * half-written result and build a summary from truncated bytes. The old
     * posture accepted any non-false return: a short write (disk-full class)
     * renamed a PARTIAL file into place — under-reported results presented
     * as truth, the exact silent-success shape this finding names.
     *
     * Returns null when the complete result stands at its final name, or the
     * failure reason (carrying the partial byte marker) when it does not —
     * the temp is unlinked on every refusal path, so no litter is left and
     * no incomplete file can ever be renamed into the completion slot.
     */
    private function publishResult(string $jobId, string $encoded): ?string
    {
        $expected = strlen($encoded);
        $resultFile = $this->resultDir . '/' . $jobId . '.json';
        $tmp = $resultFile . '.tmp.' . getmypid();

        $writer = $this->resultWriter;
        $written = $writer !== null
            ? $writer($tmp, $encoded)
            : @file_put_contents($tmp, $encoded);

        if ($written === false) {
            @unlink($tmp);
            return 'failed to write result file';
        }
        if ($written !== $expected) {
            // Mid-stream death of the byte kind: the writer streamed 23 of
            // 40 records' worth of bytes and stopped without an error. A
            // partial stream is never a completion — refuse, and say how
            // partial.
            @unlink($tmp);
            return 'partial result write: ' . $written . ' of ' . $expected . ' bytes';
        }
        // The write status is checked even on a clean end: a full-count
        // return can still sit on a file whose close lost bytes (the count
        // lied while the stream ended "happily"). Verify the on-disk size of
        // the STILL-OWNED temp — no consumer can race a `.tmp` name, unlike
        // the final one — before the atomic rename hands it to the server.
        $landed = @filesize($tmp);
        if ($landed !== $expected) {
            @unlink($tmp);
            return 'partial result write: landed ' . ($landed === false ? 0 : $landed)
                . ' of ' . $expected . ' bytes';
        }
        if (!@rename($tmp, $resultFile)) {
            @unlink($tmp);
            return 'failed to write result file';
        }
        return null;
    }

    /**
     * RUN-frame admission: every check decidable before the pipeline runs.
     * Returns null when the frame is accepted, or a `{status, error}` array
     * answering the refusal. No filesystem write and no analysis happen here.
     *
     * The dispatch handler calls this directly (so the accept-ack can fire
     * only once the frame is admitted); the reflection unit pins exercise
     * this leg in isolation, without a live pipeline.
     *
     * @param array<string,mixed> $configData
     * @return array{status:int, error:string}|null
     */
    private function admitFrame(array $configData): ?array
    {
        // Defence in depth (once-001): the worker re-validates the paths
        // against its spawn-time serve root, so a job that bypassed HTTP
        // admission (or an operator feeding the socket by hand) still
        // cannot scan outside the confinement boundary.
        $violation = Application::scanPathsError($this->serveRoot, $configData['paths'] ?? null);
        if ($violation !== null) {
            return ['status' => $violation['status'], 'error' => $violation['error']];
        }

        // Refuse config fields that must never enter through the wire (see
        // class docblock; the list itself is the one HTTP `POST /jobs`
        // admission shares).
        $refused = Application::refusedPayloadKeys($configData);
        if ($refused !== []) {
            return ['status' => 400, 'error' => 'RUN frame must not set: ' . implode(', ', $refused)];
        }

        // once-065: the optional `exact_only` posture key is shape-gated by
        // the same helper the HTTP admission runs before minting a job —
        // both layers refuse a mistyped posture identically, and a frame
        // carrying it never reaches the pipeline under either reading.
        $posture = Application::exactOnlyViolation($configData);
        if ($posture !== null) {
            return ['status' => $posture['status'], 'error' => 'RUN frame ' . $posture['error']];
        }

        return null;
    }

    /**
     * Run the full analysis pipeline for an already-admitted frame.
     *
     * @param array<string,mixed> $configData
     * @return array{status:int, body?:array<string,mixed>, error?:string}
     */
    private function executeJob(array $configData): array
    {
        try {
            /** @var list<string> $paths */
            $paths = $configData['paths'];
            $config = Config::defaults($paths);
            $state = new PipelineState($config);
            $out = new NullOutput();

            (new ScanningStage())->run($state, $out);
            (new PreprocessStage(useCache: false))->run($state, $out);
            // once-065: no hardcoded force — the frame's (already-gated)
            // `exact_only` posture decides, exactly as in the sync path,
            // so the result file carries the same full-report bytes the
            // HTTP transport would have produced for the same payload.
            (new ClusterStage(exactOnly: Application::exactOnlyPosture($configData), useClusterCache: false))->run($state, $out);
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
