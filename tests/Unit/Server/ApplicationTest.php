<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Server;

use Phpdup\Server\AnalysisWorker;
use Phpdup\Server\Application;
use Phpdup\Server\JobQueue;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    public function testUnauthenticatedPublicRequestReturns401(): void
    {
        $app = new Application(
            new JobQueue(),
            '/tmp',
            'secret-token'
        );

        $response = $app->handle('GET', '/healthz', '', []);

        $this->assertSame(401, $response['status']);
        $this->assertSame('Unauthorized', $response['body']);
    }

    public function testAuthenticatedRequestWithValidBearerTokenSucceeds(): void
    {
        $app = new Application(
            new JobQueue(),
            '/tmp',
            'secret-token'
        );

        $response = $app->handle('GET', '/healthz', '', [
            'authorization' => 'Bearer secret-token',
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertSame('ok', $response['body']);
    }

    public function testAuthenticatedRequestWithInvalidBearerTokenReturns401(): void
    {
        $app = new Application(
            new JobQueue(),
            '/tmp',
            'secret-token'
        );

        $response = $app->handle('GET', '/healthz', '', [
            'authorization' => 'Bearer wrong-token',
        ]);

        $this->assertSame(401, $response['status']);
    }

    public function testHealthzWithoutTokenSucceeds(): void
    {
        $app = new Application();

        $response = $app->handle('GET', '/healthz', '', []);

        $this->assertSame(200, $response['status']);
        $this->assertSame('ok', $response['body']);
    }

    public function testPathTraversalRejected(): void
    {
        $app = new Application(
            new JobQueue(),
            '/tmp',
            null
        );

        $response = $app->handle('POST', '/analyze', json_encode([
            'paths' => ['../etc/passwd'],
        ]), []);

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('path traversal', $response['body']);
    }

    public function testMultiplePathTraversalRejected(): void
    {
        $app = new Application(
            new JobQueue(),
            '/tmp',
            null
        );

        $response = $app->handle('POST', '/analyze', json_encode([
            'paths' => ['foo/../../bar'],
        ]), []);

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('path traversal', $response['body']);
    }

    public function testAbsolutePathRejected(): void
    {
        $app = new Application(
            new JobQueue(),
            '/tmp',
            null
        );

        $response = $app->handle('POST', '/analyze', json_encode([
            'paths' => ['/etc/passwd'],
        ]), []);

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('absolute paths are not allowed', $response['body']);
    }

    public function testValidPathWithinServeRootSucceeds(): void
    {
        $cwd = getcwd();
        chdir(sys_get_temp_dir());

        try {
            $testFile = 'phpdup_test_valid_' . uniqid() . '.php';
            file_put_contents($testFile, '<?php echo "hello";');

            $tmpDir = sys_get_temp_dir();
            $app = new Application(
                new JobQueue(),
                $tmpDir,
                null
            );

            $response = $app->handle('POST', '/analyze', json_encode([
                'paths' => [$testFile],
            ]), []);

            $this->assertNotSame(400, $response['status']);
            $this->assertSame(200, $response['status']);
            $decoded = json_decode($response['body'], true);
            $this->assertIsArray($decoded);

            @unlink($testFile);
        } finally {
            chdir($cwd);
        }
    }

    public function testNonExistentPathReturns400(): void
    {
        $tmpDir = sys_get_temp_dir();

        $app = new Application(
            new JobQueue(),
            $tmpDir,
            null
        );

        $response = $app->handle('POST', '/analyze', json_encode([
            'paths' => ['nonexistent-file-xyz.php'],
        ]), []);

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('path not found', $response['body']);
    }

    public function testEmptyPathsReturns400(): void
    {
        $app = new Application();

        $response = $app->handle('POST', '/analyze', json_encode([
            'paths' => [],
        ]), []);

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('paths must be a non-empty array', $response['body']);
    }

    public function testMissingPathsKeyReturns400(): void
    {
        $app = new Application();

        $response = $app->handle('POST', '/analyze', json_encode([]), []);

        $this->assertSame(400, $response['status']);
    }

    public function testInvalidJsonBodyReturns400(): void
    {
        $app = new Application();

        $response = $app->handle('POST', '/analyze', 'not valid json{{{', []);

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('invalid JSON', $response['body']);
    }

    public function testUnknownRouteReturns404(): void
    {
        $app = new Application();

        $response = $app->handle('GET', '/unknown', '', []);

        $this->assertSame(404, $response['status']);
        $this->assertStringContainsString('unknown route', $response['body']);
    }

    public function testJobsEnqueueReturns202(): void
    {
        $app = new Application();

        $response = $app->handle('POST', '/jobs', json_encode([
            'paths' => ['src'],
        ]), []);

        $this->assertSame(202, $response['status']);
        $decoded = json_decode($response['body'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('job_id', $decoded);
    }

    public function testGetJobStatusReturns404ForUnknownJob(): void
    {
        $app = new Application();

        $response = $app->handle('GET', '/jobs/notexist', '', []);

        $this->assertSame(404, $response['status']);
    }

    public function testBearerTokenValidationIsCaseSensitive(): void
    {
        $app = new Application(
            new JobQueue(),
            '/tmp',
            'SecretToken'
        );

        $responseValid = $app->handle('GET', '/healthz', '', [
            'authorization' => 'Bearer SecretToken',
        ]);
        $this->assertSame(200, $responseValid['status']);

        $responseLowercase = $app->handle('GET', '/healthz', '', [
            'authorization' => 'Bearer secretToken',
        ]);
        $this->assertSame(401, $responseLowercase['status']);
    }

    public function testMalformedAuthorizationHeaderReturns401(): void
    {
        $app = new Application(
            new JobQueue(),
            '/tmp',
            'secret'
        );

        $response = $app->handle('GET', '/healthz', '', [
            'authorization' => 'NotBearer secret',
        ]);

        $this->assertSame(401, $response['status']);
    }

    public function testNoTokenMeansNoAuthRequired(): void
    {
        $app = new Application(
            new JobQueue(),
            '/tmp',
            null
        );

        $response = $app->handle('GET', '/healthz', '', []);

        $this->assertSame(200, $response['status']);
    }

    public function testEmptyAuthorizationHeaderReturns401(): void
    {
        $app = new Application(
            new JobQueue(),
            '/tmp',
            'secret'
        );

        $response = $app->handle('GET', '/healthz', '', [
            'authorization' => '',
        ]);

        $this->assertSame(401, $response['status']);
    }

    // ------------------------------------------------------------------
    // shell-001: crash-proof path admission (non-string path elements)
    // ------------------------------------------------------------------

    public function testAnalyzeNonStringPathElementReturns400WithoutTypeError(): void
    {
        $app = new Application(
            new JobQueue(),
            sys_get_temp_dir(),
            null
        );

        $response = $app->handle('POST', '/analyze', '{"paths":[123]}', []);

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('paths must contain only strings', $response['body']);
    }

    public function testAnalyzeMixedPathElementsReturns400WithoutTypeError(): void
    {
        $tmpDir = sys_get_temp_dir();
        $app = new Application(new JobQueue(), $tmpDir, null);

        $file = $tmpDir . '/phpdup_mixed_' . uniqid() . '.php';
        file_put_contents($file, '<?php echo 1;');
        try {
            $response = $app->handle('POST', '/analyze', json_encode([
                'paths' => [basename($file), 123],
            ]), []);

            $this->assertSame(400, $response['status']);
            $this->assertStringContainsString('paths must contain only strings', $response['body']);
        } finally {
            @unlink($file);
        }
    }

    // ------------------------------------------------------------------
    // once-001: POST /jobs confinement at admission — no job is minted
    // ------------------------------------------------------------------

    public function testJobsEnqueueOutsideServeRootReturns400AndMintsNoJob(): void
    {
        $app = new Application(
            new JobQueue(),
            sys_get_temp_dir(),
            null
        );

        $response = $app->handle('POST', '/jobs', '{"paths":["/etc"]}', []);

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('absolute paths are not allowed', $response['body']);

        // No job may exist in the queue after a rejected enqueue.
        $list = json_decode($app->handle('GET', '/api/jobs', '', [])['body'], true);
        $this->assertSame([], $list);
    }

    public function testJobsEnqueueNonStringPathElementReturns400AndMintsNoJob(): void
    {
        $app = new Application(
            new JobQueue(),
            sys_get_temp_dir(),
            null
        );

        $response = $app->handle('POST', '/jobs', '{"paths":[123]}', []);

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('paths must contain only strings', $response['body']);

        $list = json_decode($app->handle('GET', '/api/jobs', '', [])['body'], true);
        $this->assertSame([], $list);
    }

    public function testJobsEnqueueEmptyPathsReturns400AndMintsNoJob(): void
    {
        $app = new Application(
            new JobQueue(),
            sys_get_temp_dir(),
            null
        );

        $response = $app->handle('POST', '/jobs', '{"paths":[]}', []);

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('paths must be a non-empty array', $response['body']);

        $list = json_decode($app->handle('GET', '/api/jobs', '', [])['body'], true);
        $this->assertSame([], $list);
    }

    public function testJobsEnqueueTraversalReturns400AndMintsNoJob(): void
    {
        $app = new Application(
            new JobQueue(),
            sys_get_temp_dir(),
            null
        );

        $response = $app->handle('POST', '/jobs', '{"paths":["../outside"]}', []);

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('path traversal is not allowed', $response['body']);

        $list = json_decode($app->handle('GET', '/api/jobs', '', [])['body'], true);
        $this->assertSame([], $list);
    }

    // ------------------------------------------------------------------
    // shell-S01 fix r1 (M-1): HTTP /jobs admission mirrors the worker's
    // RUN-frame refused-key gate — clean 400, no job minted, the refusal
    // reason survives instead of surfacing as a dispatch 500.
    // ------------------------------------------------------------------

    public function testJobsMlPairUrlReturns400AndMintsNoJob(): void
    {
        $app = new Application(new JobQueue(), null, null);

        // Paths themselves stay admissible (dev mode, no serve root) — the
        // 400 must come purely from the refused field, pre-mint.
        $response = $app->handle(
            'POST',
            '/jobs',
            '{"paths":["src"],"ml_pair_url":"http://evil.invalid/"}',
            []
        );

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('job payload must not set', $response['body']);
        $this->assertStringContainsString('ml_pair_url', $response['body']);

        $list = json_decode($app->handle('GET', '/api/jobs', '', [])['body'], true);
        $this->assertSame([], $list);
    }

    public function testJobsMlPairUrlNullValueStillReturns400AndMintsNoJob(): void
    {
        // Key presence decides, value-independent: an explicit null is still
        // an attempt to configure the refused field.
        $app = new Application(new JobQueue(), null, null);

        $response = $app->handle(
            'POST',
            '/jobs',
            '{"paths":["src"],"ml_pair_url":null}',
            []
        );

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('job payload must not set', $response['body']);

        $list = json_decode($app->handle('GET', '/api/jobs', '', [])['body'], true);
        $this->assertSame([], $list);
    }

    // ------------------------------------------------------------------
    // shell-S01 fix r1 (L-1): NUL bytes are a shape violation answered
    // with the same clean 400 as every other — never a ValueError out of
    // realpath() escaping as a 500.
    // ------------------------------------------------------------------

    public function testJobsNullBytePathReturns400AndMintsNoJob(): void
    {
        $app = new Application(new JobQueue(), sys_get_temp_dir(), null);

        // \u0000 decodes to a real NUL byte; pre-fix it passed the
        // string/`..` guards and realpath() threw a ValueError.
        $response = $app->handle('POST', '/jobs', '{"paths":["src\u0000evil"]}', []);

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('paths must not contain null bytes', $response['body']);

        $list = json_decode($app->handle('GET', '/api/jobs', '', [])['body'], true);
        $this->assertSame([], $list);
    }

    public function testAnalyzeNullBytePathReturns400WithoutValueError(): void
    {
        $app = new Application(new JobQueue(), sys_get_temp_dir(), null);

        $response = $app->handle('POST', '/analyze', '{"paths":["src\u0000evil"]}', []);

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('paths must not contain null bytes', $response['body']);
    }

    public function testLegitimateJobsEnqueueStillReturns202AfterAdmissionTightening(): void
    {
        // Regression pin for both fixes: a clean payload (no refused keys, no
        // NULs) must still mint a job and report it in the queue.
        $root = sys_get_temp_dir() . '/phpdup-clean-' . uniqid();
        mkdir($root);
        file_put_contents($root . '/a.php', '<?php echo 1;');
        $cwd = getcwd();
        chdir($root);

        try {
            $app = new Application(new JobQueue(), $root, null);

            $response = $app->handle('POST', '/jobs', '{"paths":["a.php"]}', []);

            $this->assertSame(202, $response['status']);
            $decoded = json_decode($response['body'], true);
            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('job_id', $decoded);

            $list = json_decode($app->handle('GET', '/api/jobs', '', [])['body'], true);
            $this->assertCount(1, $list);
        } finally {
            chdir($cwd);
            @unlink($root . '/a.php');
            @rmdir($root);
        }
    }

    // ------------------------------------------------------------------
    // once-001 defence-in-depth leg: the worker process handed the same
    // payload refuses it, independent of the HTTP layer.
    // ------------------------------------------------------------------

    public function testWorkerRefusesPayloadOutsideServeRoot(): void
    {
        $worker = new AnalysisWorker(
            '/tmp/phpdup-worker-defence-unused.sock',
            sys_get_temp_dir(),
            sys_get_temp_dir()
        );

        $processJob = new \ReflectionMethod(AnalysisWorker::class, 'processJob');

        $result = $processJob->invoke($worker, ['paths' => ['/etc']]);

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('absolute paths are not allowed', $result['error']);
    }

    public function testWorkerRefusesNonStringPathElement(): void
    {
        $worker = new AnalysisWorker(
            '/tmp/phpdup-worker-defence-unused.sock',
            sys_get_temp_dir(),
            sys_get_temp_dir()
        );

        $processJob = new \ReflectionMethod(AnalysisWorker::class, 'processJob');

        $result = $processJob->invoke($worker, ['paths' => [123]]);

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('paths must contain only strings', $result['error']);
    }

    public function testWorkerRefusesNullBytePathElement(): void
    {
        // shell-S01 fix r1 (L-1) worker leg: the re-validation shared with
        // HTTP admission answers NUL-contaminated paths before realpath()
        // could throw inside the job.
        $worker = new AnalysisWorker(
            '/tmp/phpdup-worker-defence-unused.sock',
            sys_get_temp_dir(),
            sys_get_temp_dir()
        );

        $processJob = new \ReflectionMethod(AnalysisWorker::class, 'processJob');

        $result = $processJob->invoke($worker, ['paths' => ["src\0evil"]]);

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('paths must not contain null bytes', $result['error']);
    }

    public function testWorkerRefusesEscapedRelativePath(): void
    {
        $root = sys_get_temp_dir() . '/phpdup-escape-' . uniqid();
        mkdir($root);

        $worker = new AnalysisWorker(
            '/tmp/phpdup-worker-defence-unused.sock',
            sys_get_temp_dir(),
            $root
        );

        try {
            $processJob = new \ReflectionMethod(AnalysisWorker::class, 'processJob');

            $result = $processJob->invoke($worker, ['paths' => ['../etc']]);

            $this->assertSame(400, $result['status']);
            $this->assertStringContainsString('path traversal is not allowed', $result['error']);
        } finally {
            @rmdir($root);
        }
    }

    // ------------------------------------------------------------------
    // ENGINE-SEC out-of-group 4: ml_pair_url admission leg — the RUN
    // frame refuses ml_pair_url outright (fail-closed on the wire).
    // ------------------------------------------------------------------

    public function testWorkerRefusesMlPairUrlInFrame(): void
    {
        $root = sys_get_temp_dir() . '/phpdup-mlgate-' . uniqid();
        mkdir($root);
        file_put_contents($root . '/a.php', '<?php echo 1;');
        $cwd = getcwd();
        chdir($root);

        try {
            $worker = new AnalysisWorker(
                '/tmp/phpdup-worker-defence-unused.sock',
                sys_get_temp_dir(),
                $root
            );

            $processJob = new \ReflectionMethod(AnalysisWorker::class, 'processJob');

            // Paths pass confinement, yet the frame is still refused solely
            // for carrying ml_pair_url — proving the admission leg, not the
            // path leg, rejected it.
            $result = $processJob->invoke($worker, [
                'paths'       => ['a.php'],
                'ml_pair_url' => 'http://evil.invalid/',
            ]);

            $this->assertSame(400, $result['status']);
            $this->assertStringContainsString('RUN frame must not set', $result['error']);
            $this->assertStringContainsString('ml_pair_url', $result['error']);
        } finally {
            chdir($cwd);
            @unlink($root . '/a.php');
            @rmdir($root);
        }
    }

    // ------------------------------------------------------------------
    // shell-S02 (shell-022): job-id hardening on the RUN frame. The id
    // names `<result-dir>/<id>.json`, so only the queue's own mint shape
    // reaches the filesystem; anything else is refused with an error ack
    // and writes nothing — no path escape, and the worker stays alive
    // for the next dispatch.
    // ------------------------------------------------------------------

    /**
     * Drive one RUN frame through the worker's line handler over a
     * socketpair and return the ack line ('' when the worker stayed silent).
     *
     * @param resource $client     The worker-facing end handed to handleClient().
     * @param resource $controller The test end carrying frame in / ack out.
     */
    private function workerAck($client, $controller, string $frame): string
    {
        fwrite($controller, $frame);
        $handleClient = new \ReflectionMethod(AnalysisWorker::class, 'handleClient');
        try {
            $handleClient->invoke(new AnalysisWorker(
                '/tmp/phpdup-worker-defence-unused.sock',
                $this->workerResultDir,
                null
            ), $client);
        } catch (\Throwable $e) {
            $this->fail('handleClient must never throw: ' . $e->getMessage());
        }
        $ack = fgets($controller);
        return $ack === false ? '' : rtrim($ack, "\r\n");
    }

    /** @var string results dir used by the wire tests below */
    private string $workerResultDir = '';

    /** @var list<resource> streams to close after each wire test */
    private array $workerStreams = [];

    protected function setUp(): void
    {
        $this->workerResultDir = sys_get_temp_dir() . '/phpdup-s02-' . uniqid() . '-results';
        mkdir($this->workerResultDir, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->workerStreams as $stream) {
            @fclose($stream);
        }
        $this->workerStreams = [];
        if ($this->workerResultDir !== '' && is_dir($this->workerResultDir)) {
            foreach (glob($this->workerResultDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->workerResultDir);
        }
    }

    /**
     * @return array{0: resource, 1: resource} worker-facing, test-facing ends
     */
    private function makeWorkerPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        stream_set_timeout($pair[1], 5);
        foreach ($pair as $stream) {
            $this->workerStreams[] = $stream;
        }
        return $pair;
    }

    public function testJobIdPatternMatchesTheQueueMintShape(): void
    {
        // The mirror-relationship pin: every id JobQueue mints must pass the
        // worker's admission regex, so validated dispatches are never refused.
        $queue = new JobQueue();
        for ($i = 0; $i < 25; $i++) {
            $id = $queue->enqueue(['paths' => ['src']]);
            $this->assertMatchesRegularExpression(AnalysisWorker::JOB_ID_PATTERN, $id);
        }
    }

    public function testWorkerRefusesPathTraversalJobIdAndWritesNothing(): void
    {
        $escape = 'phpdup-s02-escaped-' . uniqid();
        // `resultDir/../../<escape>.json` resolves to sys_get_temp_dir() —
        // pre-fix the unvalidated id escaped two levels up.
        $evilId = '../../' . $escape;

        [$client, $controller] = $this->makeWorkerPair();
        $ack = $this->workerAck($client, $controller, "RUN {$evilId} {\"paths\":[\"a.php\"]}\n");

        $this->assertStringStartsWith('FAIL 0', $ack);
        $this->assertStringContainsString('invalid job id', $ack);
        $this->assertStringNotContainsString('OK', $ack);

        // Nothing outside the results dir… and nothing inside it either.
        $this->assertFileDoesNotExist(sys_get_temp_dir() . '/' . $escape . '.json');
        $this->assertFileDoesNotExist(dirname($this->workerResultDir) . '/' . $escape . '.json');
        $this->assertSame([], array_values(array_filter(
            scandir($this->workerResultDir),
            static fn (string $e): bool => $e !== '.' && $e !== '..'
        )));
    }

    public function testWorkerRefusesOversizedJobIdWithoutEchoingIt(): void
    {
        // A hand-cranked id of the right alphabet but the wrong length must
        // not land on disk under any name, and the raw token must never be
        // echoed back into the line-based frame.
        [$client, $controller] = $this->makeWorkerPair();
        $ack = $this->workerAck($client, $controller, "RUN abcdef {}\n");

        $this->assertSame('FAIL 0 invalid job id', $ack);
        $this->assertStringNotContainsString('abcdef', $ack);
        $this->assertSame([], array_values(array_filter(
            scandir($this->workerResultDir),
            static fn (string $e): bool => $e !== '.' && $e !== '..'
        )));
    }

    public function testWorkerStaysAliveAfterRefusingABadJobId(): void
    {
        // Same worker object across two connections — the loop posture: the
        // refusal returns cleanly, the next dispatch is still answered.
        $worker = new AnalysisWorker(
            '/tmp/phpdup-worker-defence-unused.sock',
            $this->workerResultDir,
            null
        );
        $handleClient = new \ReflectionMethod(AnalysisWorker::class, 'handleClient');

        [$client1, $controller1] = $this->makeWorkerPair();
        fwrite($controller1, "RUN ../../evil {}\n");
        $handleClient->invoke($worker, $client1);
        $ack1 = fgets($controller1);
        $this->assertIsString($ack1);
        $this->assertStringStartsWith('FAIL 0 invalid job id', rtrim($ack1, "\r\n"));

        // Connection 2: valid id, poisoned JSON — proves the worker advanced
        // to normal RUN-frame admission instead of dying on the refusal.
        [$client2, $controller2] = $this->makeWorkerPair();
        fwrite($controller2, "RUN 0123456789abcdef not-json\n");
        $handleClient->invoke($worker, $client2);
        $ack2 = fgets($controller2);
        $this->assertIsString($ack2);
        $this->assertStringStartsWith('FAIL 0123456789abcdef invalid JSON', rtrim($ack2, "\r\n"));
    }

    public function testWorkerWritesResultUnderValidatedIdAndAcksOk(): void
    {
        // Happy-path pin for the gate: a mint-shaped id runs the job and the
        // result lands exactly at `<result-dir>/<id>.json`.
        $root = sys_get_temp_dir() . '/phpdup-s02-job-' . uniqid();
        mkdir($root);
        file_put_contents($root . '/a.php', '<?php echo 1;');
        $cwd = getcwd();
        chdir($root);

        try {
            [$client, $controller] = $this->makeWorkerPair();
            $handleClient = new \ReflectionMethod(AnalysisWorker::class, 'handleClient');
            fwrite($controller, "RUN 0123456789abcdef {\"paths\":[\"a.php\"]}\n");
            $handleClient->invoke(new AnalysisWorker(
                '/tmp/phpdup-worker-defence-unused.sock',
                $this->workerResultDir,
                $root
            ), $client);
            $ack = fgets($controller);

            $this->assertSame('OK 0123456789abcdef', $ack === false ? '' : rtrim($ack, "\r\n"));
            $this->assertFileExists($this->workerResultDir . '/0123456789abcdef.json');
            $decoded = json_decode((string)file_get_contents($this->workerResultDir . '/0123456789abcdef.json'), true);
            $this->assertIsArray($decoded);
        } finally {
            chdir($cwd);
            @unlink($root . '/a.php');
            @rmdir($root);
        }
    }

    public function testWorkerAcksFailureNotOkWhenResultWriteFails(): void
    {
        // shell-022 second leg: an unwritable results dir (analysis succeeds,
        // the write fails) must answer FAIL — never a false "OK" for a result
        // that does not exist.
        $root = sys_get_temp_dir() . '/phpdup-s02-nowrite-' . uniqid();
        mkdir($root);
        file_put_contents($root . '/a.php', '<?php echo 1;');
        chmod($this->workerResultDir, 0500); // owner without write bit: file_put_contents fails
        $cwd = getcwd();
        chdir($root);

        try {
            [$client, $controller] = $this->makeWorkerPair();
            $handleClient = new \ReflectionMethod(AnalysisWorker::class, 'handleClient');
            fwrite($controller, "RUN fedcba9876543210 {\"paths\":[\"a.php\"]}\n");
            $handleClient->invoke(new AnalysisWorker(
                '/tmp/phpdup-worker-defence-unused.sock',
                $this->workerResultDir,
                $root
            ), $client);
            $ack = fgets($controller);

            $this->assertIsString($ack);
            $this->assertStringStartsWith('FAIL fedcba9876543210', rtrim($ack, "\r\n"));
            $this->assertStringContainsString('failed to write result file', (string)$ack);
            $this->assertStringNotContainsString('OK', (string)$ack);
        } finally {
            chmod($this->workerResultDir, 0700);
            chdir($cwd);
            @unlink($root . '/a.php');
            @rmdir($root);
        }
    }
}
