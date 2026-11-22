<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Phpdup\Server\AnalysisWorker;
use Phpdup\Server\Application;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `phpdup serve` — minimal HTTP server fronting the analysis pipeline.
 *
 * Dependency-light: uses `stream_socket_server` and a hand-rolled
 * HTTP/1.1 request parser. No ReactPHP / Amp; this is meant for
 * dev / small-team use, not production. For realistic deployment
 * the Application class can be lifted into a more capable runtime
 * (Roadrunner, FrankenPHP, FPM behind nginx, etc.).
 *
 * Routes mirror docs/SERVER.md — exactly the seven method+path pairs of
 * {@see self::ROUTE_ALLOW_LIST}; anything else is 404 before the body
 * is read:
 *
 *   GET  /healthz               → 200, plain "ok"
 *   POST /analyze               → run phpdup synchronously on a posted
 *                                 paths[] payload, return JSON report
 *   POST /jobs                  → enqueue an analysis, return job id
 *                                 (async via the worker when it spawned,
 *                                 else synchronous mode; a phar build
 *                                 never spawns — sync-only posture, see
 *                                 docs/SERVER.md "Async job posture")
 *   GET  /jobs/{id}             → fetch job status / result
 *   GET  /api/jobs              → list all jobs
 *   GET  /api/jobs/{id}         → fetch job status (status-only)
 *   GET  /api/jobs/{id}/result  → fetch job result file
 *
 * Security notes
 * --------------
 * - Default bind is `127.0.0.1`; binding to a public address (`0.0.0.0`,
 *   `::`) requires the explicit `--bind-public` flag AND a `--token`.
 *   The server refuses to bind publicly without a bearer token.
 * - Inbound bodies are capped at {@see Application::MAX_BODY_BYTES} via
 *   the `Content-Length` header; oversize requests get a 413 response
 *   without ever entering the application. The gate reads ONLY anchored,
 *   exact-name `Content-Length` lines (shell-033): a decoy
 *   `X-Content-Length:` or `Content-Lengthings:` cannot hijack it, and two
 *   distinct values refuse the request with 400 before any byte-count
 *   decision (first-vs-last) is made at all.
 * - The method+path pair is matched against an allow-list before the
 *   body is read, so unknown routes do not consume request bytes.
 * - Request framing is HTTP/1.1 without chunked bodies (shell-047): a
 *   `Transfer-Encoding` header is answered 400 with an explanatory body
 *   before any body byte is read, and the header section terminates on
 *   either the canonical CRLF CRLF or the RFC 7230 bare-LF `\n\n`, so a
 *   piped LF-only client (`printf 'GET /x HTTP/1.1\n\n' | nc …`) is
 *   served immediately instead of hanging until it closes.
 * - When a token is set, all requests require `Authorization: Bearer <token>`.
 * - A loopback bind wires the Application with the bound Host authorities
 *   (once-046): {@see Application::requestTrustViolation()} refuses a Host
 *   outside them (400 — the DNS-rebinding defence), a cross-site `Origin`
 *   (403 — only same-origin fetch semantics exist, the daemon never sends
 *   CORS), and a POST whose Content-Type is not exactly `application/json`
 *   (415 — the `text/plain` simple-form CSRF defence), all from the header
 *   section alone, before a body byte is awaited; `Application::handle()`
 *   re-runs the same verdict as the authoritative second gate. A public
 *   bind mandates `--token` and keeps the gate unenforced: the bearer
 *   header is a custom shape no cross-site form can set, and behind a
 *   reverse proxy the visible Host is the proxy's, not the daemon's.
 * - Scanned paths are confined to `--serve-root` via realpath canonicalization
 *   on BOTH `/analyze` and `/jobs`: absolute paths, `..` sequences and
 *   non-string elements are rejected with 400 before any job is minted. The
 *   spawned worker re-validates every dispatched job against the same root
 *   (handed over as an escapeshellarg'd third argv token) — defence in depth.
 * - Request handling is crash-proof: an unexpected throw inside the
 *   application fails the single request with 500 and the daemon keeps
 *   serving (verified: /healthz stays up after a poisoned /analyze body).
 * - Every connection carries a per-client read deadline
 *   ({@see self::CLIENT_READ_TIMEOUT_SECONDS}, shell-007): a client that
 *   opens the socket and sends bytes but never completes its request is
 *   answered 408 Request Timeout once a single read goes silent past the
 *   deadline — checked in BOTH raw-read loops (header and body), so the
 *   serial accept loop is never frozen by one stalled client. The shell
 *   only ever bounds reads that follow traffic; this class completes the
 *   posture (once-S43) with a first-byte grace
 *   ({@see self::CLIENT_FIRST_BYTE_TIMEOUT_MS}): an accepted client
 *   that sends NOTHING is answered 408 after the 500 ms window, so a
 *   half-open slow-loris socket costs the queue at most that window and
 *   `GET /healthz` keeps answering while it stalls.
 * - The listener's accept queue is bounded explicitly
 *   ({@see self::LISTEN_BACKLOG}, once-S43) instead of inheriting PHP's
 *   default backlog, and the server is a documented KEEP-SERIAL design
 *   (one connection at a time, no worker-per-connection): see
 *   `docs/SERVER.md` "Concurrency posture" for the decision, the
 *   known-serial limitation and the sizing guidance.
 * - The results directory (`/tmp/phpdup-results-<pid>`) is created 0700 at
 *   startup and never adopted from a foreign owner: a pre-existing
 *   directory of that name owned by another user refuses the whole
 *   `serve` startup with exit 1 (runtime posture) instead of silently
 *   writing job results into an attacker-visible tree. The shutdown
 *   handler sweeps the directory so results never outlive the process.
 *   The sweep runs in reverse at STARTUP too (once-S71): results
 *   directories owned by this uid whose owning process is provably dead
 *   are removed before the bind, so crashed and `kill -9` runs cannot
 *   strand pid directories in `/tmp` forever. Live, foreign-owner,
 *   symlinked and unparseable directories are never touched; with no
 *   liveness oracle available at all the sweep does nothing — it may
 *   miss cleanup, never misfire a deletion.
 * - Every serve claims `{results-dir}/serve.pid` under a non-blocking
 *   `flock(LOCK_EX)` at startup and holds it for its lifetime
 *   (once-S44, php-dup-finder-once-048): the kernel releases the lock on
 *   EVERY death path — clean exit, signal, `kill -9`, OOM — which makes
 *   the flock the pid-reuse-proof liveness oracle the startup sweep
 *   consults first ({@see pidFileDeathProof()}, the reuse point
 *   once-S97's stale-socket leg rides), with the raw pid vote as the
 *   fallback exactly as before. A pre-existing results directory whose
 *   claim is still held refuses startup (a live serve owns that name —
 *   no adoption race, no shared tree); an unclaimed one is adopted and
 *   re-armed. The claim is what "no stale lock" means for `serve`.
 * - Security posture of the default bind (once-S71, docs/SERVER.md
 *   "Security"): a tokenless serve is reachable by EVERY user on this
 *   machine — loopback confines the network, not the uid — and such a run
 *   now prints an explicit SECURITY warning line at startup naming that
 *   fact, the serve-root confinement, and the `--token` remedy. The
 *   posture itself is unchanged (documented-local): tokenless loopback
 *   stays the default, and authentication stays mandatory for any public
 *   bind (`--bind-public` refuses to start without `--token`).
 * - SIGINT/SIGTERM terminate cleanly (shell-016): the installed handler
 *   runs the same `cleanupWorker()` the shutdown function uses — reaping
 *   the spawned worker, unlinking the worker socket and sweeping the
 *   results directory — and then exits with code 130 (the 128+SIGINT
 *   convention, kept for both signals; the 0/1/2/3 normal-completion
 *   contract in docs/CLI.md is untouched). Queued signals are also
 *   dispatched at the TOP of every accept-loop iteration, so the 30 s
 *   accept timeout can never run past one. When ext-pcntl is unavailable
 *   no handlers are installed — a graceful no-op, the same posture as
 *   WorkerPool's serial fallback without pcntl_fork (the pre-existing
 *   orphan-on-Ctrl-C behaviour is what a pcntl-less build keeps). The
 *   worker likewise self-exits once its parent dies (reparent poll,
 *   {@see \Phpdup\Server\AnalysisWorker}), so even `kill -9` on the
 *   daemon cannot leave an orphan worker loop behind.
 * - Worker death paths are classified, not trusted (once-S44,
 *   php-dup-finder-once-048). The socket name embeds this daemon's pid,
 *   so a pre-existing file at the exact spawn path is either a stale
 *   leftover of a dead predecessor (pid reuse — the readiness poll would
 *   false-ready, the worker's bind would EADDRINUSE-fail: both legs are
 *   handled — the daemon probes it, proves no live listener, and reclaims
 *   it before the spawn) or an actual live listener sharing the name, in
 *   which case nothing is unlinked: the daemon runs in sync mode naming
 *   the collision, and the worker's own bind refuses to steal a path a
 *   live worker answers. Either way a worker never ends up serving an
 *   unlinked socket forever, and `kill -9` then restart cannot wedge.
 */
final class ServeCommand extends SymfonyCommand
{
    /**
     * Base name prefix of the per-process results directory shared with
     * the worker: `/tmp/phpdup-results-<pid>`. Named (once-S71) so the
     * path builder and the startup stale sweep speak one token.
     */
    public const RESULTS_DIR_PREFIX = 'phpdup-results-';

    /** Matches a results-directory base name, capturing its owning pid. */
    private const RESULTS_DIR_PATTERN = '/^' . self::RESULTS_DIR_PREFIX . '([0-9]+)$/';

    /** Allow-list of `METHOD<space>PATH-PATTERN` routes (paths matched by regex). */
    private const ROUTE_ALLOW_LIST = [
        'GET /healthz',
        'POST /analyze',
        'POST /jobs',
        'GET /jobs/[0-9a-f]+',
        'GET /api/jobs',
        'GET /api/jobs/[0-9a-f]+',
        'GET /api/jobs/[0-9a-f]+/result',
    ];

    /**
     * Exit code `phpdup serve` terminates with when killed by SIGINT or
     * SIGTERM (shell-016): the 128+signal convention for SIGINT (128+2),
     * kept as one number for both signals so the documented signal
     * posture speaks exactly one code. This is a signal-death code, not a
     * normal-completion one — the docs-truth 0/1/2/3 contract stands
     * untouched.
     */
    public const SIGNAL_EXIT_CODE = 130;

    /**
     * Env token handing the worker the daemon's own pid (shell-016): the
     * spawn goes through an `sh -c` wrapper, so the worker's direct
     * parent is the shell — reparenting alone cannot prove the DAEMON
     * died. The pid is neither wire nor argv (the pinned spawn string is
     * untouched); an env value the worker treats as advisory: manual
     * spawns run without it and keep reparent-only detection.
     */
    public const WORKER_PID_ENV = 'PHPDUP_SERVE_PID';

    /**
     * Name of the daemon-liveness pid file inside the results directory
     * (once-S44, php-dup-finder-once-048): content is the owning serve
     * pid, the file is held under a non-blocking `flock(LOCK_EX)` for the
     * daemon's whole lifetime, and the KERNEL releases that lock on every
     * death path — clean exit, signal, `kill -9`, OOM. It is therefore
     * the one liveness oracle that survives pid reuse, the hole a
     * `posix_kill(pid, 0)` verdict cannot see: a dead serve's pid slots
     * back into the live-looking address space while its flock is
     * provably free. Consumers: {@see pidFileDeathProof()} (the startup
     * sweep's stronger dead-proof — S71's sweep "sees" this file, plan
     * shared-resources row) and once-S97's predict+@unlink socket window
     * (its queue row names the reuse). PHP ≥ 7.4 marks every fd it opens
     * FD_CLOEXEC, so the `sh -c` worker exec closes this descriptor —
     * the spawn child can never outlive-hold the daemon's lock.
     */
    public const SERVE_PID_FILE = 'serve.pid';

    /**
     * Per-client read deadline in seconds (shell-007). The daemon serves
     * requests serially, so any single read that blocks forever blocks
     * every later client; this bounds it. The window restarts on every
     * chunk that arrives, so a slow-but-live client is never cut off —
     * only a silent one. `docs/SERVER.md` documents the constant in the
     * shell-S09 pass.
     */
    public const CLIENT_READ_TIMEOUT_SECONDS = 5;

    /**
     * First-byte grace in milliseconds (once-S43): how long an ACCEPTED
     * client may stay completely silent — not one byte of a request —
     * before it is answered 408 and the connection is released. The
     * shell-S04 window only restarts once bytes start flowing, so a
     * half-open slow-loris socket (netcat connected, nothing sent)
     * would otherwise hold the serial accept loop for the full
     * {@see self::CLIENT_READ_TIMEOUT_SECONDS} per stalled connection.
     * 500 ms is the immune posture: a stalling client can keep a queued
     * `GET /healthz` from being served for at most this window, which
     * keeps the probe's worst-case latency under the one-second
     * acceptance bound, while remaining far above a real client's
     * post-connect first byte. It applies to the FIRST byte only: once
     * any request byte arrives the shell-S04 deadline takes over, so a
     * slow-but-live client is still never cut off mid-request.
     * `docs/SERVER.md` documents the posture and its sizing guidance.
     */
    public const CLIENT_FIRST_BYTE_TIMEOUT_MS = 500;

    /**
     * Explicit `listen()` backlog for the server socket (once-S43):
     * connections that arrive while the serial loop is busy with one
     * client complete their handshake and queue here instead of being
     * refused. PHP's no-context default on this box measured 32
     * (`ss -ltn` Send-Q); the explicit 64 doubles the small-team
     * headroom and — the point of the constant — makes the queue
     * depth a decision, not an accident. `docs/SERVER.md` carries the
     * sizing guidance row.
     */
    public const LISTEN_BACKLOG = 64;

    /** SIGINT/SIGKILL as raw POSIX numbers: reaping must not depend on ext-pcntl constants. */
    private const SIGNAL_INT = 2;
    private const SIGNAL_KILL = 9;

    /**
     * Bounded reap windows (shell-016): cleanupWorker used to block on
     * proc_close forever when the wrapper shell waited for a child that
     * never died. Int grace → KILL grace → the reap is total, and a
     * wedged worker can never wedge the daemon's shutdown with it.
     */
    private const REAP_GRACE_SECONDS = 1.0;

    /** Process resource for the background worker. */
    /** @var resource|null */
    private $workerProcess = null;

    /** Path to the worker Unix socket. */
    private string $workerSocket = '';

    /**
     * Open handle for this daemon's {@see SERVE_PID_FILE} claim, held for
     * the process lifetime so the flock stays armed (null = no claim —
     * either none taken or this instance inherited the path's authority
     * from an earlier instance in the same process and holds no lock).
     *
     * @var resource|null
     */
    private $pidFileHandle = null;

    /** Private results directory prepared at startup ('' = refused or already swept). */
    private string $resultDir = '';

    /**
     * Test seam supplying the OWNER uid of a pre-existing results directory
     * (unit tests cannot chown without root). Null uses the real
     * `fileowner()` stat; a probe returning a foreign uid exercises the real
     * comparison and its refusal, and `false`/`null` exercises the
     * fail-closed unstatable arm. The verdict itself is never seam-fed.
     *
     * @var (callable(string): int|false|null)|null
     */
    public $ownerProbe = null;

    /**
     * Test seam for how {@see terminateOnSignal()} ends the process (unit
     * tests must never exit the phpunit runner). Null uses the real
     * `exit(SIGNAL_EXIT_CODE)`; a probe captures the code instead while
     * every cleanup step above it runs unseamed.
     *
     * @var (callable(int): void)|null
     */
    public $signalTerminator = null;

    /**
     * Test seam for the per-client read deadline in seconds (shell-007):
     * null uses {@see self::CLIENT_READ_TIMEOUT_SECONDS}; a probe lowers
     * the window so the stalled-stream unit pins stay hermetic (no
     * wall-clock waits beyond 2 s). Only the number is seamed — the
     * timed_out detection and the 408 path run unseamed.
     */
    public ?int $readTimeoutSeconds = null;

    /**
     * Test seam for the first-byte grace in milliseconds (once-S43):
     * null uses {@see self::CLIENT_FIRST_BYTE_TIMEOUT_MS}; a probe
     * raises or lowers the window so units can pin the silent-client
     * 408 arm (and its interaction with the shell-S04 deadline) without
     * wall-clock waits. Only the number is seamed — the silent-408 and
     * flow-then-upgrade paths run unseamed, mirroring how
     * {@see $readTimeoutSeconds} treats the read deadline.
     */
    public ?int $firstByteTimeoutMs = null;

    /**
     * Test seam for the phar-detection in {@see spawnWorker()} (shell-S35):
     * null uses the real `Phar::running(false)` — which is always '' inside
     * a phpunit process started from the checkout, making the phar posture
     * otherwise untestable here. A probe string is used verbatim as the
     * running-phar path. Only the detection is seamed; the warning line and
     * the sync-mode fallthrough run unseamed, like the read-deadline seams
     * above treat their numbers.
     */
    public ?string $pharRunningOverride = null;

    /** Why the last {@see prepareResultsDir()} refused startup ('' = none). */
    private string $resultsDirRefusal = '';

    /** HTTP/1.1 reason phrases by status code. */
    private const HTTP_REASON = [
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        408 => 'Request Timeout',
        413 => 'Payload Too Large',
        415 => 'Unsupported Media Type',
        431 => 'Request Header Fields Too Large',
        500 => 'Internal Server Error',
    ];

    /**
     * Configure the `serve` command and its CLI options.
     */
    #[\Override]
    protected function configure(): void
    {
        $this->setName('serve')
            ->setDescription('Run phpdup as a minimal HTTP analysis server.')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host to bind', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'TCP port to bind', '8080')
            ->addOption(
                'bind-public',
                null,
                InputOption::VALUE_NONE,
                'Allow binding to a non-loopback address (requires --token).'
            )
            ->addOption(
                'serve-root',
                null,
                InputOption::VALUE_REQUIRED,
                'Root directory to which scanning is confined (default: CWD).',
                getcwd() ?: '.'
            )
            ->addOption(
                'token',
                null,
                InputOption::VALUE_REQUIRED,
                'Bearer token required for all requests when set. Required when --bind-public is used.'
            )
            ->addOption(
                'serve-cache',
                null,
                InputOption::VALUE_NONE,
                'Let the synchronous /analyze pipeline reuse AST/cluster caches '
                    . '(off by default: the historic serve posture re-parses fresh every request).'
            )
            ->addOption(
                'serve-workers',
                null,
                InputOption::VALUE_REQUIRED,
                'Worker count for the synchronous /analyze pipeline. Default 1 (serial) '
                    . 'prevents a per-request fork storm on many-core boxes; raise to opt '
                    . 'into parallel analysis (still capped by the WorkerPool ceiling). '
                    . '0 restores automatic CPU-count workers.',
                '1'
            );
    }

    /**
     * Validate server configuration before starting the socket.
     *
     * Returns null if valid; returns an error message string if invalid.
     * This is the testable portion of execute() — the socket/accept loop
     * lives in execute() itself so tests that only need to verify the
     * validation logic can call this method directly.
     *
     * `$serveWorkers` is the RAW `--serve-workers` string (defaulting to the
     * option's own default so 3-arg callers keep working): it is admitted
     * before any `(int)` cast, so a word or a negative can never silently
     * mean "auto" at startup.
     */
    public function validateConfig(bool $bindPublic, ?string $token, string $host, string $serveWorkers = '1'): ?string
    {
        if ($bindPublic && ($token === null || $token === '')) {
            return '--bind-public requires --token to be set (no authentication is enforced by default).';
        }

        if (!$bindPublic && !$this->isLoopback($host)) {
            return "refusing to bind to non-loopback host '{$host}' without --bind-public.";
        }

        // shell-032 r1 LOW-2: --serve-workers is validated as the RAW string
        // the operator typed. A blind `(int)` cast turned `--serve-workers
        // banana` (and any negative) into 0 — the documented "auto" posture,
        // i.e. the per-request fork storm this flag's default exists to
        // prevent, chosen by a typo. Digits only (0 = auto is legitimate);
        // everything else refuses startup with the value echoed back.
        if (!ctype_digit($serveWorkers)) {
            return "--serve-workers must be a non-negative integer (got '{$serveWorkers}'; 0 restores automatic CPU-count workers).";
        }

        return null;
    }

    /**
     * Run the accept loop until the server socket is closed.
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string)$input->getOption('host');
        $port = (int)$input->getOption('port');
        $bindPublic = (bool)$input->getOption('bind-public');
        $serveRoot = (string)$input->getOption('serve-root');
        $token = $input->getOption('token');
        // shell-032: the sync-mode cache + worker posture becomes operator-
        // selectable instead of hard-coded. --serve-cache defaults OFF (the
        // historic no-cache serve behaviour); --serve-workers defaults to 1
        // (serial) so a many-core box no longer forks detectCpuCount()
        // children to answer one small /analyze. Both only ever affect the
        // synchronous /analyze (and worker-less /jobs) pipeline; the async
        // background worker keeps its own per-job parallelism.
        $useCache = (bool)$input->getOption('serve-cache');
        $serveWorkersRaw = (string)$input->getOption('serve-workers');

        $error = $this->validateConfig($bindPublic, $token, $host, $serveWorkersRaw);
        if ($error !== null) {
            $output->writeln("<error>phpdup serve: {$error}</error>");
            return 1;
        }
        // Only validated digits reach the cast: '0' means auto, '1'+ explicit;
        // a word or negative refused startup above (r1 LOW-2).
        $serveWorkers = (int)$serveWorkersRaw;

        // Prepare the private results directory before the worker can spawn
        // or any bind happens: a foreign-owned path of the same name is a
        // hard startup refusal (exit 1, runtime posture) — never a silent
        // adoption that would expose job results to another user.
        $prepared = $this->prepareResultsDir();
        if ($prepared === null) {
            $output->writeln('<error>phpdup serve: ' . $this->resultsDirRefusal . '</error>');
            return 1;
        }
        $this->resultDir = $prepared;

        // once-S71 (once-085): /tmp hygiene and exposure honesty, both
        // before anything can answer a request. Stale results directories
        // left by dead serve runs are swept here — without this step the
        // pid-named directories accumulated across restarts forever, a
        // silent on-disk growth the bounded-memory docs never spoke of.
        $staleSwept = $this->sweepStaleResultsDirs();
        if ($staleSwept > 0) {
            $word = $staleSwept === 1 ? 'directory' : 'directories';
            $output->writeln("<comment>phpdup serve:</comment> swept {$staleSwept} stale results {$word}");
        }

        // The loopback default carries the tokenless posture; "local" has
        // always meant every user on this machine, and silence about it
        // was the finding. Say so loudly once, then serve as documented.
        $notice = self::tokenlessExposureNotice($token, $serveRoot);
        if ($notice !== '') {
            $output->writeln("<comment>phpdup serve:</comment> {$notice}");
        }

        // Register shutdown handler to clean up worker process, socket and
        // results directory. Registered before the spawn so every early
        // return path (bind failure, …) still sweeps what we created.
        register_shutdown_function(function (): void {
            $this->cleanupWorker();
        });

        // shell-016: signal handlers ride the same cleanup as the shutdown
        // function. Installed before the spawn so even a Ctrl-C during the
        // worker-socket wait reaps what exists and exits 130 — the old code
        // registered cleanup only as a shutdown function, which a
        // default-disposition signal death skips, orphaning the worker.
        $this->installSignalHandlers();

        // once-046: a loopback bind carries the request-trust gate — the
        // daemon answers ONLY its own bound authorities, so a DNS-rebound
        // Host (evil.test resolving to 127.0.0.1) and cross-site Origin
        // never reach a route, and POSTs are application/json-only. A
        // public bind always mandates --token (validateConfig above): the
        // bearer header is exactly the custom shape browsers cannot forge,
        // so the gate stays unenforced there rather than guessing which
        // Host names a reverse proxy in front of it presents.
        $trusted = $this->isLoopback($host) ? Application::loopbackAuthorities($host, $port) : [];

        // Spawn background worker for async job processing. The serve root is
        // handed to the worker as a spawn argument so it can independently
        // re-validate dispatched job paths (defence in depth). From a phar
        // build the spawn is refused up front (shell-S35): the worker cannot
        // be exec'd from inside a phar, so the operator gets the warning
        // line here and the same sync-mode Application serves the request.
        $workerPid = $this->spawnWorker($output, $serveRoot);
        if ($workerPid === null) {
            $output->writeln('<info>phpdup serve</info> running in sync mode (no worker)');
            $app = new Application(
                new \Phpdup\Server\JobQueue(),
                $serveRoot !== '' ? $serveRoot : null,
                $token ?: null,
                null,
                null,
                $useCache,
                $serveWorkers,
                $trusted
            );
        } else {
            $output->writeln("<info>phpdup serve</info> worker spawned (pid {$workerPid})");
            $app = new Application(
                new \Phpdup\Server\JobQueue(),
                $serveRoot !== '' ? $serveRoot : null,
                $token ?: null,
                $this->workerSocket,
                $this->resultDir,
                $useCache,
                $serveWorkers,
                $trusted
            );
        }

        $address = "tcp://{$host}:{$port}";

        $errno = 0;
        $errstr = '';
        // once-S43: explicit listen() backlog via the socket context — the
        // queue that absorbs fresh connections while the serial loop is
        // busy with one client. Passing it makes the depth a documented
        // decision (self::LISTEN_BACKLOG) instead of PHP's ambient default;
        // posture + sizing live in docs/SERVER.md.
        $serverContext = stream_context_create(['socket' => ['backlog' => self::LISTEN_BACKLOG]]);
        $server = @stream_socket_server(
            $address,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $serverContext
        );
        if ($server === false) {
            $output->writeln("<error>phpdup: bind failed: {$errstr}</error>");
            return 1;
        }
        $output->writeln("<info>phpdup serve</info> listening on {$address}");

        // Accept until the underlying socket goes away (the loop condition
        // really can become false during iteration, which also keeps
        // phpstan honest). Termination by SIGINT/SIGTERM does not run
        // through here at all: the installed handler cleans up and exits
        // 130 (shell-016).
        while (is_resource($server)) {
            // shell-016: dispatch queued signals at the TOP of every
            // iteration — the 30 s accept-timeout `continue` below cannot
            // run past a signal any more (the old code dispatched only at
            // the loop tail, after serving, which the timeout path skipped
            // outright). Async delivery via pcntl_async_signals() set by
            // installSignalHandlers() makes this the belt, not the only
            // path: even where async signals are unavailable, the next
            // wake-up actions the signal.
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            $client = @stream_socket_accept($server, 30);
            if ($client === false) {
                continue;
            }
            try {
                $this->handleClient($client, $app);
            } catch (\Throwable) {
                // Belt-and-braces around the transport layer itself: even a
                // failure while reading/writing this connection is contained,
                // the socket is closed and the loop accepts the next client.
            } finally {
                @fclose($client);
            }
        }
        return 0;
    }

    /**
     * Read one HTTP request from the client, dispatch it to the
     * Application, and write the response.
     *
     * @param resource $client An open stream from {@see stream_socket_accept()}.
     */
    private function handleClient($client, Application $app): void
    {
        // once-S43 + shell-007, one two-tier read bound per connection:
        // while NO byte has arrived the socket rides the short first-byte
        // deadline — an accepted-but-silent slow-loris client (netcat with
        // the connection half-open) is released with 408 after the 500 ms
        // first-byte grace, so it can never hold the serial accept loop
        // past the /healthz probe budget. The first byte that does arrive
        // hands the connection to the shell-S04 read deadline below: it
        // restarts on every chunk, so a slow-but-live client is still
        // never cut off mid-request.
        $firstByteGraceMs = $this->firstByteTimeoutMs ?? self::CLIENT_FIRST_BYTE_TIMEOUT_MS;
        stream_set_timeout($client, intdiv($firstByteGraceMs, 1000), $firstByteGraceMs % 1000 * 1000);

        $raw = '';
        // Read until end of headers, but cap headers to keep a single
        // misbehaving client from exhausting memory.
        $headerCap = 64 * 1024;
        while (!feof($client)) {
            $chunk = @fread($client, 4096);
            if ($chunk === false || $chunk === '') {
                if (self::readTimedOut($client)) {
                    $this->writeStatus($client, 408, 'Request Timeout');
                    return;
                }
                break;
            }
            if ($raw === '') {
                // shell-007: the connection is live — from here the full
                // per-client read deadline governs every later read
                // (including the body loop below).
                stream_set_timeout($client, $this->readTimeoutSeconds ?? self::CLIENT_READ_TIMEOUT_SECONDS);
            }
            $raw .= $chunk;
            if (strlen($raw) > $headerCap) {
                $this->writeStatus($client, 431, 'Request Header Fields Too Large');
                return;
            }
            // shell-047: the separator is CRLF CRLF or the lenient bare-LF
            // pair — whichever arrives first ends the header section.
            if (self::headerEnd($raw) !== null) {
                break;
            }
        }

        // shell-047: parse against the CR-stripped header block, so the
        // request line and every anchored header match see clean LF-
        // separated lines regardless of the client's line-ending mix. CR
        // carries no header content — dropping it is loss-free — and the
        // body bytes stay untouched after the separator.
        $end = self::headerEnd($raw);
        $headerBlock = str_replace("\r", '', $end === null ? $raw : substr($raw, 0, $end[0]));

        if (preg_match('#^([A-Z]+) ([^ ]+) HTTP/1\.[01](?:\n|$)#', $headerBlock, $m) !== 1) {
            $this->writeStatus($client, 400, 'Bad Request');
            return;
        }
        $method = $m[1];
        $path   = $m[2];

        if (!$this->isAllowedRoute($method, $path)) {
            $this->writeStatus($client, 404, 'Not Found');
            return;
        }

        // shell-047: this parser never decodes chunked bodies. Presence of
        // the framing header (anchored exact name — an `X-Transfer-
        // Encoding:` decoy passes) refuses the request where it belongs,
        // before a single body byte is awaited.
        if (preg_match('/^Transfer-Encoding:/mi', $headerBlock) === 1) {
            $this->writeStatus(
                $client,
                400,
                'Bad Request',
                '{"error":"Transfer-Encoding is not supported; send Content-Length"}'
            );
            return;
        }

        // shell-033: the size gate is fed only by anchored, exact-name
        // Content-Length occurrences. Repeats that agree act as one field
        // (RFC 7230 3.3.3); conflicting values are a request the daemon
        // refuses rather than a race between two readers' duplicate policy.
        $lengths = self::contentLengths($headerBlock);
        if (count(array_unique($lengths)) > 1) {
            $this->writeStatus(
                $client,
                400,
                'Bad Request',
                '{"error":"conflicting Content-Length headers"}'
            );
            return;
        }
        $expected = $lengths === [] ? null : $lengths[0];

        // Extract request headers into a lowercase-keyed array for
        // case-insensitive lookup (required for Authorization) — done on
        // the header section alone, before any body byte is awaited.
        $headers = $this->extractHeaders($headerBlock);

        // once-046: the request-trust verdict runs PRE-BODY here — a DNS-
        // rebound or cross-site POST is refused without buffering a byte
        // of its (potentially 16 MiB) payload — using the exact same
        // shared verdict Application::handle() re-runs, so an embedded
        // host that never passes through this HTTP layer keeps the gate.
        // extractHeaders keys every field by its exact lowercased name —
        // an `X-Host:`/`X-Origin:` decoy rides a different key and never
        // stands in for the real authority line (same anchoring law
        // contentLengths() enforces for Content-Length).
        $trust = Application::requestTrustViolation($app->trustedAuthorities(), $method, $headers);
        if ($trust !== null) {
            $this->writeStatus(
                $client,
                $trust['status'],
                self::HTTP_REASON[$trust['status']] ?? 'Unknown',
                '{"error":"' . $trust['error'] . '"}'
            );
            return;
        }

        $body = $end === null ? '' : substr($raw, $end[0] + $end[1]);
        // Read the claimed body, still capped by MAX_BODY_BYTES at the
        // stream level so a lying header can never buffer unbounded.
        if ($expected !== null) {
            if ($expected < 0 || $expected > Application::MAX_BODY_BYTES) {
                $this->writeStatus($client, 413, 'Payload Too Large');
                return;
            }
            while (strlen($body) < $expected && !feof($client)) {
                $chunk = @fread($client, 4096);
                if ($chunk === false || $chunk === '') {
                    if (self::readTimedOut($client)) {
                        $this->writeStatus($client, 408, 'Request Timeout');
                        return;
                    }
                    break;
                }
                $body .= $chunk;
                if (strlen($body) > Application::MAX_BODY_BYTES) {
                    $this->writeStatus($client, 413, 'Payload Too Large');
                    return;
                }
            }
            // Trim any trailing bytes past Content-Length so we do not
            // hand the application a body longer than the client claimed.
            if (strlen($body) > $expected) {
                $body = substr($body, 0, $expected);
            }
        }

        // respond() is crash-proof by contract (see its docblock): a throw
        // inside the application fails this request with 500 only.
        $response = $this->respond(
            fn(): array => $app->handle($method, $path, $body, $headers)
        );
        $reason = self::HTTP_REASON[$response['status']] ?? 'Unknown';
        $payload  = "HTTP/1.1 {$response['status']} {$reason}\r\n";
        foreach ($response['headers'] as $h => $v) {
            $payload .= "{$h}: {$v}\r\n";
        }
        $payload .= 'Content-Length: ' . strlen($response['body']) . "\r\n";
        $payload .= "Connection: close\r\n\r\n";
        $payload .= $response['body'];
        @fwrite($client, $payload);
    }

    /**
     * Run one request handler crash-proof.
     *
     * Never throws: whatever the handler raises is converted into a 500
     * response so a single poisoned request can never take the daemon down.
     *
     * @param callable():array{status:int, headers: array<string,string>, body: string} $handler
     * @return array{status:int, headers: array<string,string>, body: string}
     */
    private function respond(callable $handler): array
    {
        try {
            return $handler();
        } catch (\Throwable $e) {
            return [
                'status'  => 500,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => $this->safeErrorBody($e),
            ];
        }
    }

    /**
     * Encode a Throwable into a JSON error body that can never fail to
     * encode (invalid UTF-8 in exception messages is substituted).
     */
    private function safeErrorBody(\Throwable $e): string
    {
        $body = json_encode(
            ['error' => 'internal server error: ' . $e->getMessage()],
            JSON_INVALID_UTF8_SUBSTITUTE
        );
        return $body === false ? '{"error":"internal server error"}' : $body;
    }

    /**
     * Return true when `$host` is a loopback address (IPv4 or IPv6).
     */
    private function isLoopback(string $host): bool
    {
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }
        // Any 127.x.x.x is loopback for IPv4.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($host, '127.');
        }
        return false;
    }

    /**
     * Match `$method $path` against {@see ServeCommand::ROUTE_ALLOW_LIST}.
     */
    private function isAllowedRoute(string $method, string $path): bool
    {
        foreach (self::ROUTE_ALLOW_LIST as $route) {
            [$m, $p] = explode(' ', $route, 2);
            if ($m !== $method) {
                continue;
            }
            if (preg_match('#^' . $p . '$#', $path) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Locate the header/body separator in a raw request, honouring both
     * the canonical CRLF CRLF and the RFC 7230 bare-LF leniency a piped
     * `printf 'GET /healthz HTTP/1.1\n\n' | nc …` client sends
     * (shell-047). The earliest separator wins; the two byte pairs never
     * shadow each other (`"\r\n\r\n"` contains no `"\n\n"` and vice
     * versa), so the searches are independent.
     *
     * @return array{0:int,1:int}|null [offset of separator, separator
     *                                  length], null while it has not
     *                                  fully arrived
     */
    private static function headerEnd(string $raw): ?array
    {
        $crlf = strpos($raw, "\r\n\r\n");
        $lf = strpos($raw, "\n\n");
        if ($lf === false) {
            return $crlf === false ? null : [$crlf, 4];
        }
        if ($crlf === false || $lf < $crlf) {
            return [$lf, 2];
        }

        return [$crlf, 4];
    }

    /**
     * Every genuine Content-Length value in the CR-stripped header block
     * (shell-033). The pattern is anchored to line starts and pinned to
     * the exact field name, so `X-Content-Length:` and
     * `Content-Lengthings:` no longer reach the size gate; every real
     * occurrence is COLLECTED — the caller treats a lone distinct value
     * as the body length (repeats allowed per RFC 7230 3.3.3) and any
     * conflict as a 400, replacing the old first-substring regex that
     * sized the body by one policy while extractHeaders keyed duplicates
     * by another.
     *
     * @return list<int>
     */
    private static function contentLengths(string $headerBlock): array
    {
        $matched = preg_match_all('/^Content-Length:[ \t]*+(\d+)[ \t]*+$/mi', $headerBlock, $m);
        if ($matched === false || $matched === 0) {
            return [];
        }

        $lengths = [];
        foreach ($m[1] as $digits) {
            $lengths[] = (int)$digits;
        }

        return $lengths;
    }

    /**
     * Extract HTTP headers from the CR-stripped, LF-separated header
     * block (shell-047 normalisation) and return them as a lowercase-
     * keyed array for case-insensitive lookup.
     *
     * Duplicate policy (shell-033): LAST-WINS is the documented posture
     * for every header, including `content-length` as it is handed to
     * the application. `Content-Length` conflicts never get here —
     * handleClient refuses them with 400 before the size gate and this
     * extraction both see a header block with at most one distinct value.
     *
     * @return array<string,string>
     */
    private function extractHeaders(string $headerBlock): array
    {
        $headers = [];
        foreach (explode("\n", $headerBlock) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        return $headers;
    }

    /**
     * Send a minimal HTTP/1.1 status response and close. The body is
     * optional: with none, the bytes are exactly the pre-shell-033
     * `Content-Length: 0` framing; refusal codes that owe the client a
     * reason pass an explanatory body and pick up its Content-Type.
     *
     * @param resource $client
     */
    private function writeStatus($client, int $code, string $reason, string $body = ''): void
    {
        $message = "HTTP/1.1 {$code} {$reason}\r\n";
        if ($body !== '') {
            $message .= "Content-Type: application/json\r\n";
        }
        $message .= 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n";
        @fwrite($client, $message . $body);
    }

    /**
     * True when the last read on `$client` ended because the per-client
     * read deadline fired (shell-007). The socket layer raises
     * `timed_out` in the stream metadata when a blocking read returns
     * empty on the deadline (an EOF close raises `eof` instead, which is
     * NOT a timeout — it keeps the pre-existing short-request path).
     * Consulted in BOTH raw-read loops, so a stall while sending headers
     * and a stall mid-body take the same 408 road.
     *
     * @param resource $client
     */
    private static function readTimedOut($client): bool
    {
        return stream_get_meta_data($client)['timed_out'] === true;
    }

    /**
     * Spawn the background worker process.
     *
     * Returns the worker PID on success, null on failure (worker
     * not available or failed to start). The worker socket path is
     * stored in {@see $this->workerSocket}.
     *
     * A phar run is not a failure to paper over: {@see self::pharRunning()}
     * detects the artifact and the method returns null with one explicit
     * warning line (shell-S35 packaging decision — the matching limitation
     * row lives in docs/SERVER.md "Async job posture").
     */
    private function spawnWorker(OutputInterface $output, string $serveRoot): ?int
    {
        // shell-S35: the phar carries no worker copy, and that is a
        // deliberate packaging decision rather than an oversight. A spawn
        // goes through `sh -c`, and neither side of it can execute a script
        // stored inside a phar: the shell sees `phar:///…/bin/phpdup-worker`
        // as a nonexistent program (rc 127), and `php` refuses to open a
        // phar:// URL as its main script ("Could not open input file").
        // Shaking a `phar://` path at either one would only mis-fire the
        // sync-mode notice, so the packaging posture is stated here in one
        // warning line and `docs/SERVER.md` carries the matching row.
        if ($this->pharRunning() !== '') {
            $output->writeln(
                '<comment>phpdup serve: running from the phar build — the async worker cannot be spawned (phar:// scripts are not executable); running in sync mode</comment>'
            );
            return null;
        }

        $workerBin = __DIR__ . '/../../bin/phpdup-worker';
        if (!is_file($workerBin) || !is_executable($workerBin)) {
            $output->writeln('<comment>worker binary not found, running in sync mode</comment>');
            return null;
        }

        if ($this->resultDir === '') {
            // Invariant of execute(): the results dir is prepared (or startup
            // was refused) before any spawn attempt. A direct call without it
            // must not produce a worker with nowhere to write.
            $output->writeln('<comment>result dir not prepared, running in sync mode</comment>');
            return null;
        }
        $resultDir = $this->resultDir;

        $this->workerSocket = sys_get_temp_dir() . '/phpdup-worker-' . getmypid() . '.sock';

        // once-S44 (php-dup-finder-once-048): a file already at the exact
        // socket path is a fact to classify, never to trust. The only
        // way one exists here is a dead predecessor whose pid the kernel
        // has just handed to this process (the name embeds getmypid()).
        // As litter it would make the readiness poll below false-ready
        // at t=0 — jobs dispatched toward a socket no live worker binds —
        // and it would make the worker's own bind answer EADDRINUSE. A
        // LIVE listener is a different animal: stealing that name would
        // strand an orphan worker serving an unlinked socket forever
        // while this daemon talked to its replacement (the finding's
        // split-brain). Probe first — zero-byte connect, the worker's
        // silent-probe posture: stale → reclaim now, before the spawn,
        // so readiness can only ever observe a file our own worker made;
        // live → fall to sync mode naming the collision, and forget the
        // path so no cleanup ever unlinks a socket that is not ours.
        if (file_exists($this->workerSocket)) {
            if (AnalysisWorker::socketHasLiveListener($this->workerSocket)) {
                $output->writeln(
                    '<comment>phpdup serve: a live worker is already listening on '
                        . $this->workerSocket . ' (a reused daemon pid with an unburied worker?); '
                        . 'running in sync mode</comment>'
                );
                $this->workerSocket = '';
                return null;
            }
            @unlink($this->workerSocket);
        }

        $command = $this->workerCommand($workerBin, $this->workerSocket, $resultDir, $serveRoot);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        // Explicit env (shell-016): PHP's proc_open replaces — never
        // merges — the environment when an array is given, so the parent
        // environment is carried in verbatim and only the serve-pid token
        // is added. That token is what lets the worker prove the DAEMON
        // died even though the `sh -c` wrapper is its direct parent.
        //
        // shell-S39 (php-dup-finder-shell-052(c)): spawn under a
        // temporary restrictive umask. The worker creates its Unix
        // socket with stream_socket_server(), whose inode mode is
        // 0777 &~ umask AT THE INSTANT OF BIND — the child's own
        // chmod(0600) runs only after (the race window the finding
        // names). A child inherits the parent's umask across
        // fork+exec, so tightening it around THIS proc_open makes the
        // socket private from its first moment (0600 under 0177) and
        // every file the child creates in its opening breath
        // owner-only; the worker's chmod(0600) stays as the belt it
        // always was. The window covers only the spawn itself — the
        // parent creates nothing else inside it — and the old mask is
        // restored on both the success and the failure path.
        $spawnUmask = umask(0o177);
        try {
            $this->workerProcess = proc_open(
                $command,
                $descriptors,
                $pipes,
                null,
                self::workerEnvironment()
            );
        } finally {
            umask($spawnUmask);
        }
        if (!is_resource($this->workerProcess)) {
            $output->writeln('<comment>failed to spawn worker, running in sync mode</comment>');
            $this->workerSocket = '';
            return null;
        }

        // Close unused pipes
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }

        // Wait for socket to become available (poll with 5s timeout)
        $timeout = 5;
        $start = microtime(true);
        while (microtime(true) - $start < $timeout) {
            if (file_exists($this->workerSocket)) {
                return proc_get_status($this->workerProcess)['pid'];
            }
            usleep(50000); // 50ms
        }

        // Socket didn't appear, kill the worker
        $this->cleanupWorker();
        $output->writeln('<comment>worker socket timeout, running in sync mode</comment>');
        return null;
    }

    /**
     * Build the worker spawn command line.
     *
     * Protocol: `bin/phpdup-worker <socket> <result-dir> [<serve-root>]`,
     * plus the {@see self::WORKER_PID_ENV} env token carrying the daemon
     * pid (shell-016 — the worker's daemon-liveness reference survives the
     * `sh -c` wrapper that proc_open puts in front of every string spawn).
     * Every token is escapeshellarg'd, and the serve root travels as the
     * third argv token — never as serialized state — so the worker can
     * independently re-run path confinement on dispatched jobs.
     */
    private function workerCommand(string $workerBin, string $socket, string $resultDir, string $serveRoot): string
    {
        $tokens = [$workerBin, $socket, $resultDir];
        if ($serveRoot !== '') {
            $tokens[] = $serveRoot;
        }
        return implode(' ', array_map('escapeshellarg', $tokens));
    }

    /**
     * Install the SIGINT/SIGTERM handlers that turn a kill into a clean
     * shutdown (shell-016).
     *
     * `pcntl_async_signals(true)` makes a blocked `stream_socket_accept()`
     * return into the handler immediately, so the daemon reacts in
     * milliseconds instead of after its 30 s accept timeout; the
     * in-loop `pcntl_signal_dispatch()` stays as the belt for builds
     * where the async call is disabled. When ext-pcntl is not loaded (or
     * `pcntl_signal` is disabled) this is a deliberate graceful no-op —
     * same posture as WorkerPool's serial fallback when pcntl_fork is
     * missing — and the pre-fix default dispositions apply.
     */
    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal') || !defined('SIGINT') || !defined('SIGTERM')) {
            return;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        pcntl_signal(SIGINT, $this->terminateOnSignal(...));
        pcntl_signal(SIGTERM, $this->terminateOnSignal(...));
    }

    /**
     * One signal = full shutdown: reap the worker, unlink the socket and
     * sweep the results directory (the unseamed {@see cleanupWorker()},
     * idempotent against the registered shutdown function), then end the
     * process with {@see self::SIGNAL_EXIT_CODE} — for both SIGINT and
     * SIGTERM, one documented number.
     */
    private function terminateOnSignal(int $signal): void
    {
        $this->cleanupWorker();

        $terminator = $this->signalTerminator;
        if ($terminator !== null) {
            $terminator(self::SIGNAL_EXIT_CODE);
            return;
        }

        exit(self::SIGNAL_EXIT_CODE);
    }

    /**
     * Build the worker environment: the daemon's full current env plus
     * {@see self::WORKER_PID_ENV} (proc_open replaces environments, it
     * does not merge — see the spawn call site).
     *
     * @return array<string,string>
     */
    public static function workerEnvironment(): array
    {
        $env = [];
        foreach (getenv() as $key => $value) {
            $env[(string)$key] = (string)$value;
        }
        $env[self::WORKER_PID_ENV] = (string)getmypid();
        return $env;
    }

    /**
     * The running phar's filesystem path, or '' when executing from a plain
     * checkout (shell-S35). One line over {@see \Phar::running(false)} so
     * {@see $pharRunningOverride} can pin the phar posture in unit tests.
     */
    private function pharRunning(): string
    {
        return $this->pharRunningOverride ?? \Phar::running(false);
    }

    /**
     * Clean up the worker process, socket and results directory.
     *
     * The spawn is `sh -c '<worker> ...'`, and a wrapper shell that
     * waits for its child survives SIGINT — the old bare
     * proc_terminate + proc_close therefore hung this method (and the
     * whole signal shutdown) forever whenever the child did not die
     * (shell-016 live probe). The reap is now total and bounded: signal
     * the shell's own children first (through /proc where readable),
     * SIGINT the wrapper, wait one grace, escalate to SIGKILL, wait
     * again. Worst case the whole leg costs the two graces, never
     * forever.
     */
    private function cleanupWorker(): void
    {
        // once-S44: drop the liveness claim first — the sweep below
        // removes the directory the pid file lives in, and an inherited
        // (un-flock-able) handle would linger otherwise. On a signal
        // death this runs before the 130 exit; on any other death the
        // kernel closed the fd and released the flock anyway — the file
        // then simply rides the directory sweep.
        $this->releaseServePidFile();

        if (is_resource($this->workerProcess)) {
            $status = proc_get_status($this->workerProcess);
            if ($status['running'] && function_exists('posix_kill')) {
                foreach (self::workerTreePids($status['pid']) as $childPid) {
                    @posix_kill($childPid, self::SIGNAL_INT);
                }
            }
            @proc_terminate($this->workerProcess, self::SIGNAL_INT);
            if (!self::reapedWithin($this->workerProcess, self::REAP_GRACE_SECONDS)) {
                @proc_terminate($this->workerProcess, self::SIGNAL_KILL);
                self::reapedWithin($this->workerProcess, self::REAP_GRACE_SECONDS);
            }
            @proc_close($this->workerProcess);
            $this->workerProcess = null;
        }

        if ($this->workerSocket !== '' && file_exists($this->workerSocket)) {
            @unlink($this->workerSocket);
            $this->workerSocket = '';
        }

        $this->sweepResultDir();
    }

    /**
     * True once the direct spawn child (the wrapper shell) has stopped
     * running within `$seconds`; false only after the full window — a
     * bounded poll, never a blocking wait.
     *
     * @param resource $process
     */
    private static function reapedWithin($process, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            usleep(20000);
            $stillRunning = proc_get_status($process)['running'];
        } while ($stillRunning && microtime(true) < $deadline);

        return !$stillRunning;
    }

    /**
     * Direct children of `$ppid` — the php worker behind the `sh -c`
     * wrapper. Cheap path: the Linux task-children file. Fallback: one
     * /proc stat scan (shutdown-only work). No /proc (macOS): an empty
     * list, and the bounded SIGKILL escalation plus the worker's own
     * serve-pid poll carry the reap instead — graceful no-op posture.
     *
     * @return list<int>
     */
    private static function workerTreePids(int $ppid): array
    {
        $listed = @file_get_contents("/proc/{$ppid}/task/{$ppid}/children");
        if ($listed !== false) {
            $pids = [];
            foreach (preg_split('/\s+/', trim($listed)) ?: [] as $token) {
                if ($token !== '' && ctype_digit($token)) {
                    $pids[] = (int)$token;
                }
            }
            return $pids;
        }

        $pids = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $statFile) {
            $stat = @file_get_contents($statFile);
            if ($stat === false) {
                continue;
            }
            // comm is parenthesized and may contain spaces: fields after
            // the LAST ')' are unambiguous — [0]=state, [1]=ppid.
            $close = strrpos($stat, ')');
            if ($close === false) {
                continue;
            }
            $fields = preg_split('/\s+/', trim(substr($stat, $close + 1)));
            if ($fields !== false && ($fields[1] ?? '') === (string)$ppid) {
                $pids[] = (int)basename(dirname($statFile));
            }
        }
        return $pids;
    }

    /**
     * Remove the results directory and everything the worker wrote into it,
     * so job results never outlive the serve process. Bounded to the
     * pid-derived directory this process owns — a second belt behind the
     * startup ownership check; foreign or unexpected paths are left alone.
     */
    private function sweepResultDir(): void
    {
        $dir = $this->resultDir;
        $this->resultDir = '';

        if ($dir === '' || $dir !== self::resultDirPath() || is_link($dir) || !is_dir($dir)) {
            return;
        }

        $entries = @scandir($dir);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                // The worker only ever writes plain `<jobid>.json` files
                // here (job ids are format-validated); a stray subdirectory
                // is never ours to delete — leaving it also fails the rmdir
                // below, which is the honest outcome.
                if (!is_dir($path) || is_link($path)) {
                    @unlink($path);
                }
            }
        }
        @rmdir($dir);
    }

    /**
     * The pid-derived results directory shared with the worker child.
     */
    public static function resultDirPath(): string
    {
        return sys_get_temp_dir() . '/' . self::RESULTS_DIR_PREFIX . getmypid();
    }

    /**
     * Resolve the per-process results directory, enforcing private mode.
     *
     * Posture (shell-021):
     * - missing            → created with mode 0700 (chmod defeats the umask);
     * - ours, pre-existing → adopted and tightened to 0700 (an old 0755
     *                        leftover from a crashed run never stays world-readable);
     * - foreign-owned      → startup refusal ({@see resultsDirRefusal()});
     * - not a directory / unfixable → refusal too (fail-closed).
     *
     * Returns the usable directory path, or null when startup must be refused.
     */
    public function prepareResultsDir(): ?string
    {
        $this->resultsDirRefusal = '';
        $dir = self::resultDirPath();

        if (is_link($dir)) {
            // Never write results through a symlink we did not create.
            $this->resultsDirRefusal = "results path {$dir} is a symlink; refusing to use it";
            return null;
        }

        if (is_dir($dir)) {
            $error = $this->defaultOwnershipCheck($dir);
            if ($error !== null && $error !== '') {
                $this->resultsDirRefusal = $error;
                return null;
            }
        } elseif (!@mkdir($dir, 0700) && !is_dir($dir)) {
            $this->resultsDirRefusal = "cannot create results directory {$dir}";
            return null;
        }

        // mkdir()'s mode argument is masked by the process umask; chmod makes
        // the 0700 intent absolute — then verify, refusing on any doubt.
        if (!@chmod($dir, 0700)) {
            $this->resultsDirRefusal = "cannot enforce mode 0700 on results directory {$dir}";
            return null;
        }
        clearstatcache(true, $dir);
        $mode = @fileperms($dir);
        if ($mode === false || ($mode & 0777) !== 0700) {
            $this->resultsDirRefusal = "results directory {$dir} is not private (mode 0700)";
            return null;
        }

        // once-S44 (php-dup-finder-once-048): the directory is private and
        // ours — arm the liveness claim inside it before anything else
        // can answer a request. Fail-closed like every other leg: a serve
        // that cannot prove its own death-path liveness refuses startup
        // instead of leaving the next serve (or S71's sweep) to guess.
        if (!$this->claimServePidFile($dir)) {
            return null;
        }

        return $dir;
    }

    /**
     * Write and flock the daemon-liveness pid file inside `$dir`
     * ({@see self::SERVE_PID_FILE}) — {@see prepareResultsDir()} calls
     * this as its last leg, so the directory is already proven private.
     *
     * Returns true when the claim governs (this process holds the lock,
     * or this same process already holds it from an earlier instance),
     * false with {@see $resultsDirRefusal} set when startup must refuse.
     *
     * The claim order is load-bearing: flock FIRST, content SECOND. A
     * sweep-side probe can therefore never see a written pid file whose
     * writer had not already locked it — "file present, lock free,
     * content is the dir's pid" is an exact dead-proof, while a live
     * daemon mid-claim either carries no content yet or already holds
     * the lock the probe is trying to take.
     *
     * No process-lifetime claim registry: one phpunit process runs many
     * ServeCommand instances over the same pid-derived results directory,
     * but the kernel makes the second instance's flock(LOCK_NB) fail with
     * the FIRST instance still truthfully the owner — the owner-is-this-pid
     * accept below resolves that in-process overlap without ever sharing a
     * stream between instances (a shared fd would let whichever release
     * lands first close the other's still-advertised lock).
     */
    private function claimServePidFile(string $dir): bool
    {
        $path = $dir . '/' . self::SERVE_PID_FILE;

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            $this->resultsDirRefusal = "cannot open the serve pid file {$path}";
            return false;
        }
        if (!@chmod($path, 0600)) {
            @fclose($handle);
            $this->resultsDirRefusal = "cannot enforce mode 0600 on the serve pid file {$path}";
            return false;
        }

        if (@flock($handle, LOCK_EX | LOCK_NB)) {
            $pid = (string)getmypid();
            @ftruncate($handle, 0);
            if (@fwrite($handle, $pid . "\n") !== strlen($pid) + 1 || !@fflush($handle)) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
                $this->resultsDirRefusal = "cannot write the serve pid file {$path}";
                return false;
            }
            $this->pidFileHandle = $handle;
            return true;
        }

        // The lock is held by another open file description. The path
        // embeds getmypid(), so the holder can only be this same process
        // (an earlier ServeCommand instance still alive in the run — the
        // kernel gives no two live processes one pid, and PHP's
        // FD_CLOEXEC bars a spawn from inheriting the fd anyway). Read the
        // owner's pid and judge: ours, accept without taking a second
        // handle (the true owner's release governs); anything else, the
        // claim cannot be trusted, refuse.
        $owner = trim((string)@file_get_contents($path));
        @fclose($handle);
        if ($owner === (string)getmypid()) {
            $this->pidFileHandle = null; // the lock's real owner governs
            return true;
        }
        $this->resultsDirRefusal = "results directory {$dir} carries a pid file locked by pid "
            . $owner . " while this process is pid " . getmypid() . '; refusing to share it';
        return false;
    }

    /**
     * Release this instance's pid-file claim: unlock and close the stream
     * THIS instance opened (idempotent; an inherited accept carries no
     * handle and releases nothing, since the kernel drops the lock with
     * its owner anyway). cleanupWorker() runs this first so the shutdown
     * sweep's rmdir is never racing a file this process still has open.
     */
    private function releaseServePidFile(): void
    {
        $handle = $this->pidFileHandle;
        $this->pidFileHandle = null;
        if ($handle !== null) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * The flock-based dead-proof over one results directory (once-S44) —
     * the oracle {@see sweepStaleResultsDirs()} runs in front of the
     * legacy pid vote, and the public reuse point for once-S97's
     * stale-socket window (pair it with the directory path derived from
     * {@see self::RESULTS_DIR_PREFIX} + the socket path's pid).
     *
     * Returns true when a pid file exists, its flock is FREE, and its
     * content names the directory's pid — the writer died (the kernel
     * released the lock on every death path, `kill -9` included) and pid
     * reuse cannot fake that verdict. Returns false when the flock is
     * HELD — a live serve owns this directory and no posix_kill answer
     * may outvote it. Returns null when the directory carries no usable
     * oracle (pre-S44 leftover, empty content mid-claim, unreadable
     * file): the caller falls back to {@see pidStillAlive()}, so the
     * sweep may miss cleanup, never misfire a deletion.
     *
     * Any lock this probe takes is released before returning — the sweep
     * deletes the file, it never claims it.
     */
    public static function pidFileDeathProof(string $dir, int $dirPid): ?bool
    {
        $path = $dir . '/' . self::SERVE_PID_FILE;
        if ($dirPid <= 0 || !is_file($path)) {
            return null;
        }
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return null;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);
            return false; // held: a live daemon owns this directory
        }
        clearstatcache(true, $path);
        $content = trim((string)@file_get_contents($path));
        @flock($handle, LOCK_UN);
        @fclose($handle);

        // A free lock only proves death once the claim ever COMPLETED:
        // empty or non-numeric content means the writer died between
        // opening and writing — no verdict is available, so answer null
        // and let the legacy pid vote decide. Completed content naming a
        // FOREIGN pid inside this pid-named directory is an anomaly no
        // sweep may delete into: that's the false ("do not touch") side.
        if ($content === '' || !ctype_digit($content)) {
            return null;
        }

        return (int)$content === $dirPid;
    }

    /**
     * Reason the last {@see prepareResultsDir()} returned null ('' if none).
     */
    public function resultsDirRefusal(): string
    {
        return $this->resultsDirRefusal;
    }

    /**
     * Real ownership check: an existing results directory must belong to
     * this process's own user or startup is refused. Fail-closed: an
     * unreadable owner counts as foreign. The only seam here supplies the
     * owner uid ({@see $ownerProbe}) — the comparison and both refusal
     * messages run unseamed, so tests pin the load-bearing arm itself.
     */
    private function defaultOwnershipCheck(string $dir): ?string
    {
        $probe = $this->ownerProbe;
        $owner = $probe !== null ? ($probe)($dir) : @fileowner($dir);
        if ($owner === false || $owner === null) {
            return "cannot stat the owner of results directory {$dir}";
        }
        if ($owner !== getmyuid()) {
            return "results directory {$dir} is owned by uid {$owner}, not this process (uid "
                . getmyuid() . '); refusing to adopt a foreign directory';
        }
        return null;
    }

    /**
     * The startup warning text for a tokenless bind (once-S71) — pure, so
     * the exact sentence is unit-pinned and docs/SERVER.md can quote it
     * verbatim. Returns '' when a token is set (there is nothing to warn
     * about: every route then answers 401 to unauthenticated callers).
     *
     * The honesty the finding asked for: "keep it local" historically
     * meant "reachable by every user on this machine", and an empty
     * `--serve-root` disables path confinement entirely, so that arm is
     * named differently instead of claiming a confine that is not there.
     */
    public static function tokenlessExposureNotice(?string $token, string $serveRoot): string
    {
        if ($token !== null && $token !== '') {
            return '';
        }

        $confine = $serveRoot === ''
            ? 'scans are NOT confined (--serve-root is empty: any path this uid can read is scannable)'
            : 'scans are confined to serve root ' . $serveRoot;

        return 'SECURITY: serving without --token — every user on this machine can reach this '
            . 'server unauthenticated (loopback confines the network, not the other users). '
            . $confine . '. Pass --token to require a bearer header on every route, or run '
            . 'behind a proxy that authenticates. See docs/SERVER.md, "Security".';
    }

    /**
     * Startup hygiene for the pid-derived results directories (once-S71):
     * the shutdown sweep only ever fires for processes that reach it, so
     * a crashed or `kill -9`'d serve strands `/tmp/phpdup-results-<pid>`
     * with its 0600 result files forever. Before binding, this method
     * removes the directories of this uid whose owning process is
     * provably dead — since once-S44 the serve.pid flock is the FIRST
     * dead-proof (exact under pid reuse, the one case the raw pid vote
     * can lie about), the legacy liveness vote its fallback — and
     * returns the number of directories fully removed.
     *
     * Containment law, deliberately as tight as the shutdown sweep's:
     * only `RESULTS_DIR_PREFIX<digits>` names are considered; this
     * process's own directory, symlinks, foreign-owned or unstatable
     * directories, and anything with a live pid are skipped untouched;
     * inside a dead directory only plain files are unlinked (a stray
     * subdirectory is never ours to delete — it makes the rmdir fail,
     * and a directory we could not fully remove is NOT counted). With no
     * pid-liveness oracle (no ext-posix and no /proc) nothing can be
     * proven dead, so nothing is removed: the sweep may miss cleanup,
     * never misfire a deletion.
     */
    public function sweepStaleResultsDirs(?string $tempDir = null): int
    {
        $tempDir ??= sys_get_temp_dir();
        $candidates = glob($tempDir . '/' . self::RESULTS_DIR_PREFIX . '*');
        if ($candidates === false) {
            return 0;
        }

        $ownPid = getmypid();
        $removed = 0;
        foreach ($candidates as $path) {
            if (preg_match(self::RESULTS_DIR_PATTERN, basename($path), $digits) !== 1) {
                continue; // not a results directory — a foreign name sharing the prefix
            }
            $pid = (int)$digits[1];
            if ($pid === $ownPid) {
                continue; // ours: prepareResultsDir adopted it, cleanupWorker owns its removal
            }
            if (is_link($path) || !is_dir($path)) {
                continue; // never traverse or delete through a symlink
            }
            if (!$this->staleDirOwnedByUs($path)) {
                continue; // another uid's tree (or unstatable): not ours to touch
            }
            // once-S44 (php-dup-finder-once-048): the flock dead-proof is
            // consulted BEFORE the legacy pid vote, because it is the
            // only oracle that survives pid reuse in either direction.
            // A HELD lock is the strongest alive-vote available — a live
            // serve owns the directory, full stop. An acquirable lock on
            // a file that names this directory's pid is exact death — the
            // kernel released it on the owner's last close, whatever a
            // reused pid number now claims. Only when the directory
            // carries no usable claim (a pre-S44 leftover, or a file
            // caught mid-claim with no content) does the sweep fall back
            // to pidStillAlive(), preserving the S71 posture verbatim:
            // may miss cleanup, never misfire a deletion.
            $deathProof = self::pidFileDeathProof($path, $pid);
            if ($deathProof === false) {
                continue; // the flock is held: a live serve owns this tree
            }
            if ($deathProof === null && self::pidStillAlive($pid)) {
                continue; // no claim here: the legacy live-pid vote governs
            }
            if (self::removeDeadResultsDir($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Ownership gate for the startup sweep, riding the SAME seam as
     * {@see defaultOwnershipCheck()}: the probe supplies the owner uid,
     * the comparison is unseamed, and unstatable counts as foreign
     * (fail-closed — the sweep skips what it cannot prove is ours).
     */
    private function staleDirOwnedByUs(string $dir): bool
    {
        $probe = $this->ownerProbe;
        $owner = $probe !== null ? ($probe)($dir) : @fileowner($dir);

        return $owner !== false && $owner !== null && $owner === getmyuid();
    }

    /**
     * Is this pid in use? `posix_kill(0)` answers for any uid — a foreign
     * live process yields EPERM, which still means "alive" (the
     * conservative direction for a deletion gate). Falls back to Linux's
     * /proc; with neither oracle available, every pid is treated as alive
     * so the sweep becomes a no-op rather than an unverified deletion.
     */
    private static function pidStillAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return true; // digits but no plausible pid: never risk the delete
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0) === true;
        }
        if (is_dir('/proc')) {
            return is_dir('/proc/' . $pid);
        }

        return true;
    }

    /**
     * Remove a proven-dead, proven-ours results directory. Same rule as
     * the shutdown sweep: unlink only plain files, never recurse, and let
     * a stray subdirectory defeat the rmdir — the honest false answer
     * that keeps it out of the removed count.
     */
    private static function removeDeadResultsDir(string $dir): bool
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (!is_dir($path) || is_link($path)) {
                @unlink($path);
            }
        }

        return @rmdir($dir) === true;
    }
}
