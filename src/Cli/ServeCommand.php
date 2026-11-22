<?php
declare(strict_types=1);

namespace Phpdup\Cli;

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
 * Routes mirror docs/SERVER.md:
 *
 *   GET  /healthz       → liveness check
 *   POST /analyze       → synchronous analysis, JSON report response
 *   POST /jobs          → enqueue an analysis (async via the worker when it
 *                         spawned, else synchronous demo mode)
 *   GET  /jobs/{id}     → poll job status / result
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
 */
final class ServeCommand extends SymfonyCommand
{
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

    /** Why the last {@see prepareResultsDir()} refused startup ('' = none). */
    private string $resultsDirRefusal = '';

    /** HTTP/1.1 reason phrases by status code. */
    private const HTTP_REASON = [
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        404 => 'Not Found',
        408 => 'Request Timeout',
        413 => 'Payload Too Large',
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
            );
    }

    /**
     * Validate server configuration before starting the socket.
     *
     * Returns null if valid; returns an error message string if invalid.
     * This is the testable portion of execute() — the socket/accept loop
     * lives in execute() itself so tests that only need to verify the
     * validation logic can call this method directly.
     */
    public function validateConfig(bool $bindPublic, ?string $token, string $host): ?string
    {
        if ($bindPublic && ($token === null || $token === '')) {
            return '--bind-public requires --token to be set (no authentication is enforced by default).';
        }

        if (!$bindPublic && !$this->isLoopback($host)) {
            return "refusing to bind to non-loopback host '{$host}' without --bind-public.";
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

        $error = $this->validateConfig($bindPublic, $token, $host);
        if ($error !== null) {
            $output->writeln("<error>phpdup serve: {$error}</error>");
            return 1;
        }

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

        // Spawn background worker for async job processing. The serve root is
        // handed to the worker as a spawn argument so it can independently
        // re-validate dispatched job paths (defence in depth).
        $workerPid = $this->spawnWorker($output, $serveRoot);
        if ($workerPid === null) {
            $output->writeln('<info>phpdup serve</info> running in sync mode (no worker)');
            $app = new Application(
                new \Phpdup\Server\JobQueue(),
                $serveRoot !== '' ? $serveRoot : null,
                $token ?: null
            );
        } else {
            $output->writeln("<info>phpdup serve</info> worker spawned (pid {$workerPid})");
            $app = new Application(
                new \Phpdup\Server\JobQueue(),
                $serveRoot !== '' ? $serveRoot : null,
                $token ?: null,
                $this->workerSocket,
                $this->resultDir
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

        // Extract request headers into a lowercase-keyed array for
        // case-insensitive lookup (required for Authorization).
        $headers = $this->extractHeaders($headerBlock);

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
     */
    private function spawnWorker(OutputInterface $output, string $serveRoot): ?int
    {
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
        $this->workerProcess = proc_open(
            $command,
            $descriptors,
            $pipes,
            null,
            self::workerEnvironment()
        );
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
        return sys_get_temp_dir() . '/phpdup-results-' . getmypid();
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

        return $dir;
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
}
