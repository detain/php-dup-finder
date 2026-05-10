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
 *   GET  /healthz          → 200, plain "ok"
 *   POST /analyze          → run phpdup synchronously on a posted
 *                            paths[] payload, return JSON report
 *   POST /jobs             → enqueue an analysis, return job id
 *   GET  /jobs/{id}        → fetch job status / result
 *
 * For demo purposes the queue runs synchronously inside POST /jobs
 * so a client can fetch status afterwards. A real deployment would
 * dispatch work to an external runner.
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
 */
final class Application
{
    /** Hard ceiling on accepted JSON body size (16 MiB). */
    public const MAX_BODY_BYTES = 16 * 1024 * 1024;

    /**
     * @param JobQueue $queue Job queue used by the /jobs routes.
     */
    public function __construct(
        private readonly JobQueue $queue = new JobQueue(),
    ) {
    }

    /**
     * Dispatch a single HTTP request to the appropriate route handler.
     *
     * @param string                $method  HTTP method (GET/POST/…).
     * @param string                $path    Request path (no query string).
     * @param string                $body    Raw request body.
     * @param array<string,string>  $headers Request headers (currently unused; reserved for auth).
     * @return array{status:int, headers: array<string,string>, body: string}
     */
    public function handle(string $method, string $path, string $body, array $headers = []): array
    {
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
            $id = $this->queue->enqueue($payload);
            // Synchronous execution for demo simplicity; a production
            // server would push the work to a worker pool here.
            $this->queue->markRunning($id);
            $result = $this->runAnalyze($payload);
            if ($result['status'] === 200) {
                try {
                    $decoded = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $decoded = null;
                }
                $this->queue->markCompleted($id, is_array($decoded) ? $decoded : []);
            } else {
                $this->queue->markFailed($id, $result['body']);
            }
            return $this->json(202, ['job_id' => $id]);
        }
        if ($method === 'GET' && preg_match('#^/jobs/([0-9a-f]+)$#', $path, $m) === 1) {
            $job = $this->queue->get($m[1]);
            if ($job === null) {
                return $this->json(404, ['error' => 'job not found']);
            }
            return $this->json(200, $job);
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
}
