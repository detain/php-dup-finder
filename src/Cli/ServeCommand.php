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
 *   POST /jobs          → enqueue an analysis (synchronous demo impl)
 *   GET  /jobs/{id}     → poll job status / result
 *
 * Security notes
 * --------------
 * - Default bind is `127.0.0.1`; binding to a public address (`0.0.0.0`,
 *   `::`) requires the explicit `--bind-public` flag AND a `--token`.
 *   The server refuses to bind publicly without a bearer token.
 * - Inbound bodies are capped at {@see Application::MAX_BODY_BYTES} via
 *   the `Content-Length` header; oversize requests get a 413 response
 *   without ever entering the application.
 * - The method+path pair is matched against an allow-list before the
 *   body is read, so unknown routes do not consume request bytes.
 * - When a token is set, all requests require `Authorization: Bearer <token>`.
 * - Scanned paths are confined to `--serve-root` via realpath canonicalization;
 *   absolute paths and `..` sequences are rejected with 400.
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

    /** Process resource for the background worker. */
    /** @var resource|null */
    private $workerProcess = null;

    /** Path to the worker Unix socket. */
    private string $workerSocket = '';

    /** HTTP/1.1 reason phrases by status code. */
    private const HTTP_REASON = [
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        404 => 'Not Found',
        413 => 'Payload Too Large',
        431 => 'Request Header Fields Too Large',
        500 => 'Internal Server Error',
    ];

    /**
     * Configure the `serve` command and its CLI options.
     */
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

        // Spawn background worker for async job processing
        $workerPid = $this->spawnWorker($output);
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
                $this->getResultDir()
            );
        }

        // Register shutdown handler to clean up worker process
        register_shutdown_function(function (): void {
            $this->cleanupWorker();
        });

        $address = "tcp://{$host}:{$port}";

        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server($address, $errno, $errstr);
        if ($server === false) {
            $output->writeln("<error>phpdup: bind failed: {$errstr}</error>");
            return 1;
        }
        $output->writeln("<info>phpdup serve</info> listening on {$address}");

        // Accept until the underlying socket goes away (SIGINT, fd
        // close, …). Using `is_resource($server)` as the loop
        // condition keeps PhpStan happy — the condition really can
        // become false during the loop.
        while (is_resource($server)) {
            $client = @stream_socket_accept($server, 30);
            if ($client === false) {
                continue;
            }
            try {
                $this->handleClient($client, $app);
            } finally {
                @fclose($client);
            }
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
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
        $raw = '';
        // Read until end of headers, but cap headers to keep a single
        // misbehaving client from exhausting memory.
        $headerCap = 64 * 1024;
        while (!feof($client)) {
            $chunk = @fread($client, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
            if (strlen($raw) > $headerCap) {
                $this->writeStatus($client, 431, 'Request Header Fields Too Large');
                return;
            }
            if (str_contains($raw, "\r\n\r\n")) {
                break;
            }
        }
        if (preg_match('#^([A-Z]+) ([^ ]+) HTTP/1\.[01]\r\n#', $raw, $m) !== 1) {
            $this->writeStatus($client, 400, 'Bad Request');
            return;
        }
        $method = $m[1];
        $path   = $m[2];

        if (!$this->isAllowedRoute($method, $path)) {
            $this->writeStatus($client, 404, 'Not Found');
            return;
        }

        $bodyStart = strpos($raw, "\r\n\r\n");
        $body = $bodyStart === false ? '' : substr($raw, $bodyStart + 4);
        // Top-level Content-Length parsing for simple JSON bodies.
        if (preg_match('/Content-Length:\s*(\d+)/i', $raw, $cm) === 1) {
            $expected = (int)$cm[1];
            if ($expected < 0 || $expected > Application::MAX_BODY_BYTES) {
                $this->writeStatus($client, 413, 'Payload Too Large');
                return;
            }
            while (strlen($body) < $expected && !feof($client)) {
                $chunk = @fread($client, 4096);
                if ($chunk === false || $chunk === '') {
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
        $headers = $this->extractHeaders($raw);

        $response = $app->handle($method, $path, $body, $headers);
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
     * Extract HTTP headers from the raw request and return them as a
     * lowercase-keyed array for case-insensitive lookup.
     *
     * @return array<string,string>
     */
    private function extractHeaders(string $raw): array
    {
        $headers = [];
        $headerEnd = strpos($raw, "\r\n\r\n");
        $headerBlock = $headerEnd !== false ? substr($raw, 0, $headerEnd) : $raw;
        foreach (explode("\r\n", $headerBlock) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        return $headers;
    }

    /**
     * Send a minimal HTTP/1.1 status response and close.
     *
     * @param resource $client
     */
    private function writeStatus($client, int $code, string $reason): void
    {
        @fwrite($client, "HTTP/1.1 {$code} {$reason}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    }

    /**
     * Spawn the background worker process.
     *
     * Returns the worker PID on success, null on failure (worker
     * not available or failed to start). The worker socket path is
     * stored in {@see $this->workerSocket}.
     */
    private function spawnWorker(OutputInterface $output): ?int
    {
        $workerBin = __DIR__ . '/../../bin/phpdup-worker';
        if (!is_file($workerBin) || !is_executable($workerBin)) {
            $output->writeln('<comment>worker binary not found, running in sync mode</comment>');
            return null;
        }

        $resultDir = $this->getResultDir();
        if (!is_dir($resultDir) && !@mkdir($resultDir, 0755, true)) {
            $output->writeln('<comment>result dir not writable, running in sync mode</comment>');
            return null;
        }

        $this->workerSocket = sys_get_temp_dir() . '/phpdup-worker-' . getmypid() . '.sock';
        $command = "{$workerBin} {$this->workerSocket} {$resultDir}";

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $this->workerProcess = proc_open($command, $descriptors, $pipes);
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
     * Clean up the worker process and socket.
     */
    private function cleanupWorker(): void
    {
        if (is_resource($this->workerProcess)) {
            @proc_terminate($this->workerProcess, 2);
            @proc_close($this->workerProcess);
            $this->workerProcess = null;
        }

        if ($this->workerSocket !== '' && file_exists($this->workerSocket)) {
            @unlink($this->workerSocket);
            $this->workerSocket = '';
        }
    }

    /**
     * Get the result directory path for worker output.
     */
    private function getResultDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-results-' . getmypid();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
}
