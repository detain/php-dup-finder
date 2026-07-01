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
 * The worker is spawned by ServeCommand and runs the full analysis pipeline
 * using the same stages as the CLI command.
 */
final class AnalysisWorker
{
    /** @var resource|null */
    private $socket;

    private string $socketPath;

    private string $resultDir;

    private bool $running = false;

    /**
     * @param string $socketPath Path to the Unix socket to listen on.
     * @param string $resultDir  Directory where result files are written.
     */
    public function __construct(string $socketPath, string $resultDir)
    {
        $this->socketPath = $socketPath;
        $this->resultDir = $resultDir;
    }

    /**
     * Run the worker main loop, accepting and processing jobs.
     *
     * Blocks until the socket is closed or an unrecoverable error occurs.
     */
    public function run(): void
    {
        $this->setupSocket();
        $this->running = true;

        while ($this->running) {
            $client = @stream_socket_accept($this->socket, 5);
            if ($client === false) {
                continue;
            }
            try {
                $this->handleClient($client);
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
     * @param resource $client
     */
    private function handleClient($client): void
    {
        $line = @fgets($client);
        if ($line === false || $line === '') {
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

        try {
            $configData = json_decode($configJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            @fwrite($client, "FAIL {$jobId} invalid JSON: {$e->getMessage()}\n");
            return;
        }

        $result = $this->processJob($configData);
        if ($result['status'] === 200) {
            // Write result file
            $resultFile = $this->resultDir . '/' . $jobId . '.json';
            file_put_contents($resultFile, json_encode($result['body'], JSON_THROW_ON_ERROR));
            @fwrite($client, "OK {$jobId}\n");
        } else {
            @fwrite($client, "FAIL {$jobId} {$result['error']}\n");
        }
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
            $paths = $configData['paths'] ?? null;
            if (!is_array($paths) || $paths === []) {
                return ['status' => 400, 'error' => 'paths must be a non-empty array'];
            }

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
