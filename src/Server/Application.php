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
 * to the background worker and returns 202 immediately. Without a worker
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
 *   realpath canonicalization. Absolute paths and paths containing
 *   `..` are rejected with a 400 response.
 */
final class Application
{
    /** Hard ceiling on accepted JSON body size (16 MiB). */
    public const MAX_BODY_BYTES = 16 * 1024 * 1024;

    /**
     * @param JobQueue       $queue        Job queue used by the /jobs routes.
     * @param string|null    $serveRoot    Absolute path to which scanning is confined.
     * @param string|null    $token        Bearer token required for all requests when set.
     * @param string|null    $workerSocket Path to the Unix socket for async job dispatch.
     * @param string|null    $resultDir    Directory where result files are written by the worker.
     */
    public function __construct(
        private readonly JobQueue $queue = new JobQueue(),
        private readonly ?string $serveRoot = null,
        private readonly ?string $token = null,
        private readonly ?string $workerSocket = null,
        private readonly ?string $resultDir = null,
    ) {
    }

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
            $job = $this->queue->get($m[1]);
            if ($job === null) {
                return $this->json(404, ['error' => 'job not found']);
            }
            return $this->json(200, $job);
        }
        // Async API routes
        if ($method === 'GET' && $path === '/api/jobs') {
            return $this->json(200, $this->queue->list());
        }
        if ($method === 'GET' && preg_match('#^/api/jobs/([0-9a-f]+)$#', $path, $m) === 1) {
            $status = $this->queue->status($m[1]);
            if ($status === null) {
                return $this->json(404, ['error' => 'job not found']);
            }
            return $this->json(200, $status);
        }
        if ($method === 'GET' && preg_match('#^/api/jobs/([0-9a-f]+)/result$#', $path, $m) === 1) {
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
        $paths = $payload['paths'] ?? null;
        if (!is_array($paths) || $paths === []) {
            return $this->json(400, ['error' => 'paths must be a non-empty array']);
        }
        // Validate paths are within serveRoot before scanning.
        if (($violation = $this->validatePaths($paths)) !== null) {
            return $violation;
        }
        try {
            $config = Config::defaults($paths);
            $state = new PipelineState($config);
            $out   = new NullOutput();
            (new ScanningStage())->run($state, $out);
            (new PreprocessStage(useCache: false))->run($state, $out);
            (new ClusterStage(exactOnly: true, useClusterCache: false))->run($state, $out);
            (new RefactorStage(useCache: false))->run($state, $out);
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
     * When a worker socket is configured, the job is dispatched to the
     * background worker and this returns immediately (202 Accepted).
     * When no worker is configured, runs synchronously (sync/demo mode).
     *
     * @param array<string,mixed> $payload
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private function enqueueJob(array $payload): array
    {
        $id = $this->queue->enqueue($payload);

        if ($this->workerSocket !== null && $this->resultDir !== null) {
            // Async mode: dispatch to background worker
            $dispatched = $this->dispatchToWorker($id, $payload);
            if (!$dispatched) {
                $this->queue->markFailed($id, 'failed to dispatch to worker');
                return $this->json(500, ['error' => 'failed to dispatch job to worker']);
            }
            return $this->json(202, ['job_id' => $id]);
        }

        // Sync mode (no worker): run analysis inline
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
     * Wire protocol: RUN <jobId> <configJson>\n
     *
     * @param string               $jobId
     * @param array<string,mixed>  $payload
     * @return bool True if successfully dispatched.
     */
    private function dispatchToWorker(string $jobId, array $payload): bool
    {
        if ($this->workerSocket === null) {
            return false;
        }

        $socket = @stream_socket_client('unix://' . $this->workerSocket, $errno, $errstr, 1);
        if ($socket === false) {
            return false;
        }

        try {
            $configJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $message = "RUN {$jobId} {$configJson}\n";
            $written = @fwrite($socket, $message);
            if ($written === false || $written !== strlen($message)) {
                return false;
            }
            // Read response (non-blocking with 5s timeout)
            stream_set_timeout($socket, 5);
            $response = @fgets($socket);
            if ($response === false || $response === '') {
                return false;
            }
            return str_starts_with(trim($response), 'OK ' . $jobId);
        } finally {
            @fclose($socket);
        }
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
     * Validate that all paths in the request are inside serveRoot.
     *
     * @param list<string> $paths
     * @return array{status:int, headers: array<string,string>, body: string}|null
     */
    private function validatePaths(array $paths): ?array
    {
        if ($this->serveRoot === null) {
            return null;
        }
        $serveRootResolved = realpath($this->serveRoot);
        if ($serveRootResolved === false) {
            return $this->json(500, ['error' => 'serve-root does not exist']);
        }
        // Ensure serveRoot ends with DIRECTORY_SEPARATOR for prefix matching.
        $serveRootResolved .= DIRECTORY_SEPARATOR;

        foreach ($paths as $path) {
            // Reject absolute paths.
            if (str_starts_with($path, '/')) {
                return $this->json(400, ['error' => 'absolute paths are not allowed: ' . $path]);
            }
            // Reject paths containing traversal sequences.
            if (str_contains($path, '..')) {
                return $this->json(400, ['error' => 'path traversal is not allowed: ' . $path]);
            }
            $resolved = realpath($path);
            if ($resolved === false) {
                // File does not exist; still check containment to avoid bypassing
                // via symlinks outside serveRoot.
                return $this->json(400, ['error' => 'path not found: ' . $path]);
            }
            if (!str_starts_with($resolved . DIRECTORY_SEPARATOR, $serveRootResolved)) {
                return $this->json(400, ['error' => 'path is outside serve-root: ' . $path]);
            }
        }
        return null;
    }
}
