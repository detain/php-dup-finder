<?php
declare(strict_types=1);

namespace Phpdup\Server;

use Phpdup\Cli\Config;
use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ReportStage;
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
 */
final class Application
{
    public function __construct(
        private readonly JobQueue $queue = new JobQueue(),
    ) {
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, headers: array<string,string>, body: string}
     */
    public function handle(string $method, string $path, string $body, array $headers = []): array
    {
        if ($method === 'GET' && $path === '/healthz') {
            return $this->plain(200, 'ok');
        }
        if ($method === 'POST' && $path === '/analyze') {
            return $this->runAnalyze($this->jsonBody($body));
        }
        if ($method === 'POST' && $path === '/jobs') {
            $payload = $this->jsonBody($body);
            $id = $this->queue->enqueue($payload);
            // Synchronous execution for demo simplicity; a production
            // server would push the work to a worker pool here.
            $this->queue->markRunning($id);
            $result = $this->runAnalyze($payload);
            if ($result['status'] === 200) {
                $decoded = json_decode($result['body'], true);
                $this->queue->markCompleted($id, is_array($decoded) ? $decoded : []);
            } else {
                $this->queue->markFailed($id, $result['body']);
            }
            return $this->json(202, ['job_id' => $id]);
        }
        if ($method === 'GET' && preg_match('#^/jobs/([0-9a-f]+)$#', $path, $m)) {
            $job = $this->queue->get($m[1]);
            if ($job === null) {
                return $this->json(404, ['error' => 'job not found']);
            }
            return $this->json(200, $job);
        }
        return $this->json(404, ['error' => 'unknown route']);
    }

    /**
     * @param array<string,mixed> $payload
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

    /** @return array<string,mixed> */
    private function jsonBody(string $body): array
    {
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int, headers: array<string,string>, body: string}
     */
    private function json(int $status, array $payload): array
    {
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => (string)json_encode($payload, JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
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
