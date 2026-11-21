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
 * A poisoned frame never kills the worker: every per-client handler is
 * wrapped so the loop keeps serving the next dispatch.
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

    /** @var resource|null */
    private $socket;

    private string $socketPath;

    private string $resultDir;

    /** Optional absolute path to which job scanning is confined. */
    private ?string $serveRoot;

    private bool $running = false;

    /**
     * @param string      $socketPath Path to the Unix socket to listen on.
     * @param string      $resultDir  Directory where result files are written.
     * @param string|null $serveRoot  Serve root jobs are confined to; null disables
     *                                confinement (manual/dev spawns only).
     */
    public function __construct(string $socketPath, string $resultDir, ?string $serveRoot = null)
    {
        $this->socketPath = $socketPath;
        $this->resultDir = $resultDir;
        $this->serveRoot = $serveRoot;
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
