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

        $admit = new \ReflectionMethod(AnalysisWorker::class, 'admitFrame');

        $result = $admit->invoke($worker, ['paths' => ['/etc']]);

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

        $admit = new \ReflectionMethod(AnalysisWorker::class, 'admitFrame');

        $result = $admit->invoke($worker, ['paths' => [123]]);

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

        $admit = new \ReflectionMethod(AnalysisWorker::class, 'admitFrame');

        $result = $admit->invoke($worker, ['paths' => ["src\0evil"]]);

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
            $admit = new \ReflectionMethod(AnalysisWorker::class, 'admitFrame');

            $result = $admit->invoke($worker, ['paths' => ['../etc']]);

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

            $admit = new \ReflectionMethod(AnalysisWorker::class, 'admitFrame');

            // Paths pass confinement, yet the frame is still refused solely
            // for carrying ml_pair_url — proving the admission leg, not the
            // path leg, rejected it.
            $result = $admit->invoke($worker, [
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

    /**
     * Same drive as {@see workerAck()} but through a worker built with an
     * explicit shell-049 read cap, so the overflow path stays testable over
     * a socketpair without actually pushing megabytes.
     *
     * @param resource $client     The worker-facing end handed to handleClient().
     * @param resource $controller The test end carrying frame in / ack out.
     */
    private function workerAckCapped($client, $controller, string $frame, int $cap): string
    {
        fwrite($controller, $frame);
        $handleClient = new \ReflectionMethod(AnalysisWorker::class, 'handleClient');
        try {
            $handleClient->invoke(new AnalysisWorker(
                '/tmp/phpdup-worker-defence-unused.sock',
                $this->workerResultDir,
                null,
                $cap
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
        // `resultDir/../<escape>.json` resolves to sys_get_temp_dir() — the
        // very plane the fs assertions below inspect, so "writes nothing
        // outside the results dir" is filesystem-proven (pre-fix the
        // unvalidated id escaped one level up and landed beside the dir).
        $evilId = '../' . $escape;

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

    public function testWorkerAcceptsThenErrsWhenResultWriteFails(): void
    {
        // shell-022 + shell-032: the frame is admitted, so the worker answers
        // the ACCEPT-ack (`OK <id>`) first — that is the pre-work contract now.
        // When the analysis succeeds but the result cannot be written, the
        // SECOND frame is `ERR <id>`, and no result file lands: a false
        // completion is impossible because completion is the FILE, never the
        // accept-ack. The server fails such a job via its absence-deadline.
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
            $accept = rtrim((string)fgets($controller), "\r\n");
            $second = rtrim((string)fgets($controller), "\r\n");

            // First frame: the accept-ack (admission succeeded).
            $this->assertSame('OK fedcba9876543210', $accept);
            // Second frame: the post-work failure, with the reason.
            $this->assertStringStartsWith('ERR fedcba9876543210', $second);
            $this->assertStringContainsString('failed to write result file', $second);
            // No result file published under any name.
            $this->assertFileDoesNotExist($this->workerResultDir . '/fedcba9876543210.json');
        } finally {
            chmod($this->workerResultDir, 0700);
            chdir($cwd);
            @unlink($root . '/a.php');
            @rmdir($root);
        }
    }

    public function testAdmissionRefusedFrameNeverReceivesTheAcceptAck(): void
    {
        // shell-032 r1 MEDIUM-1: the composition pin. The pre-admission FAIL
        // legs (bad id, oversized, invalid JSON) have socketpair coverage, and
        // the admission DECISION has the reflection pins — but no test drove a
        // frame that passes every pre-admission leg yet is REFUSED BY
        // ADMISSION (valid 16-hex id + well-formed JSON + confined paths,
        // carrying a refused key) through handleClient. That composition —
        // admitFrame → FAIL before the OK ever writes — lived only in the
        // source order. A reorder that moves the accept-ack above admission
        // would 202 a job the worker never accepted, stranding a phantom
        // RUNNING row until the absence-deadline. This pin drives the refused
        // frame over a real socketpair and holds the wire to one FAIL line.
        $root = sys_get_temp_dir() . '/phpdup-s08-admit-' . uniqid();
        mkdir($root);
        file_put_contents($root . '/a.php', '<?php echo 1;');
        $cwd = getcwd();
        chdir($root);

        try {
            [$client, $controller] = $this->makeWorkerPair();
            $handleClient = new \ReflectionMethod(AnalysisWorker::class, 'handleClient');
            fwrite($controller, "RUN 0123456789abcdef {\"paths\":[\"a.php\"],\"ml_pair_url\":\"http://evil.invalid/\"}\n");
            $handleClient->invoke(new AnalysisWorker(
                '/tmp/phpdup-worker-defence-unused.sock',
                $this->workerResultDir,
                $root
            ), $client);
            // The refusal path wrote its one line and returned; close the
            // worker end NOW — and drop it from the teardown ledger first,
            // because fclose() of an already-closed resource raises a
            // TypeError that @ does not suppress — so the drain below ends at
            // a real EOF instead of the 5 s socket timeout.
            $this->workerStreams = array_values(array_filter(
                $this->workerStreams,
                static fn ($s): bool => $s !== $client
            ));
            fclose($client);
            $lines = [];
            while (($line = fgets($controller)) !== false) {
                $lines[] = rtrim($line, "\r\n");
            }

            $this->assertNotEmpty($lines, 'a refused frame must be answered, not ignored');
            $this->assertStringStartsWith(
                'FAIL 0123456789abcdef RUN frame must not set',
                $lines[0],
                'the FIRST wire line must be the admission FAIL, never an accept-ack'
            );
            $this->assertStringContainsString('ml_pair_url', $lines[0]);
            foreach ($lines as $wired) {
                // No accept-ack, and no ERR either — ERR is the POST-accept
                // failure frame; a refused frame never reached acceptance.
                $this->assertFalse(str_starts_with($wired, 'OK '), "no accept-ack on a refused frame: {$wired}");
                $this->assertFalse(str_starts_with($wired, 'ERR '), "no post-accept ERR on a refused frame: {$wired}");
            }
            $this->assertCount(1, $lines, 'refusal is answered with exactly one FAIL frame');

            // Nothing ran and nothing was written: no result under the id, no
            // tmp litter, the results dir stays empty.
            $this->assertFileDoesNotExist($this->workerResultDir . '/0123456789abcdef.json');
            $this->assertSame([], array_values(array_filter(
                scandir($this->workerResultDir),
                static fn (string $e): bool => $e !== '.' && $e !== '..'
            )));
        } finally {
            chdir($cwd);
            @unlink($root . '/a.php');
            @rmdir($root);
        }
    }

    // ------------------------------------------------------------------
    // shell-032: ack-at-accept protocol — the server no longer blocks on the
    // analysis. These legs drive the async dispatcher through the
    // workerAckProbe seam (the kernel socket I/O is the only replaced part),
    // so the accept-ack verdict and the result-file reconcile run for real.
    // ------------------------------------------------------------------

    /**
     * A probe that answers the dispatcher's accept-ack the way an admitting
     * worker does: the id echoed back from the RUN frame it was handed.
     *
     * @param 'OK'|'FAIL'|'none' $reply
     */
    private function ackProbe(string $reply): \Closure
    {
        return static function (string $socketPath, string $message) use ($reply): ?string {
            $id = explode(' ', $message)[1] ?? '';
            if ($reply === 'none') {
                return null; // worker unreachable / wedged socket
            }

            return "{$reply} {$id}" . ($reply === 'FAIL' ? ' admission refused' : '');
        };
    }

    private function makeAsyncApp(?float &$now = null): Application
    {
        $resultDir = sys_get_temp_dir() . '/phpdup-s08-results-' . uniqid();
        mkdir($resultDir, 0700);
        $app = new Application(new JobQueue(), null, null, '/tmp/phpdup-s08-unused.sock', $resultDir);
        $clock = &$now;
        $app->asyncClock = static function () use (&$clock): float {
            return $clock ?? 1_000_000.0;
        };
        return $app;
    }

    public function testSlowAsyncJobIsNeverFailedByDispatchItCompletesViaResultFile(): void
    {
        // The headline split-brain cure: the worker accept-acks immediately
        // and the (long-running) job later drops its result file. The POST
        // must return 202 and the job must land COMPLETED once the file
        // appears — no fixed timeout can false-fail it.
        $now = 1_000_000.0;
        $app = $this->makeAsyncApp($now);
        $resultDir = (new \ReflectionProperty(Application::class, 'resultDir'))->getValue($app);

        // The accept-ack carries the minted job id, only known after enqueue,
        // so the probe echoes it back from the RUN frame it is handed.
        $app->workerAckProbe = $this->ackProbe('OK');

        $response = $app->handle('POST', '/jobs', '{"paths":["src"]}', []);
        $this->assertSame(202, $response['status']);
        $id = json_decode($response['body'], true)['job_id'];
        $this->assertIsString($id);

        // Right after accept the job is RUNNING (acked) — not failed — and no
        // result exists yet. A poll at this instant must not report failure.
        $statusBefore = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
        $this->assertSame(JobQueue::STATUS_RUNNING, $statusBefore['status']);

        // The analysis finishes well past the old 5 s ack window: the result
        // file lands. A poll now reconciles it to completed.
        $now += 3600.0;
        file_put_contents($resultDir . '/' . $id . '.json', json_encode([
            'files' => 42, 'blocks' => 100, 'clusters' => 3, 'config' => [],
        ]));

        $statusAfter = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
        $this->assertSame(JobQueue::STATUS_COMPLETED, $statusAfter['status']);
        $this->assertSame(42, $statusAfter['result']['files']);

        // And the full result is retrievable.
        $result = $app->handle('GET', '/api/jobs/' . $id . '/result', '', []);
        $this->assertSame(200, $result['status']);
    }

    public function testCrashedWorkerLandsFailedViaResultFileAbsenceDeadline(): void
    {
        // A worker that accepts then dies (no result file ever) must NOT
        // linger "running" forever: past the absence-deadline the job is
        // honestly failed. Before the deadline it stays running.
        $now = 2_000_000.0;
        $app = $this->makeAsyncApp($now);
        $app->asyncResultDeadlineSeconds = 300;
        $app->workerAckProbe = $this->ackProbe('OK'); // accepted… then it dies (no file)

        $id = json_decode($app->handle('POST', '/jobs', '{"paths":["src"]}', [])['body'], true)['job_id'];

        // Within the deadline: still running (a slow job is given room).
        $now += 100.0;
        $this->assertSame(
            JobQueue::STATUS_RUNNING,
            json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true)['status']
        );

        // Past the deadline with no result file: failed.
        $now += 400.0;
        $status = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
        $this->assertSame(JobQueue::STATUS_FAILED, $status['status']);
        $this->assertStringContainsString('did not produce a result', $status['error']);
    }

    public function testDispatchRejectionMarksFailedWithoutMintingSuccess(): void
    {
        // A worker whose socket answers FAIL (a real dispatch/admission
        // failure) or never answers (unreachable/wedged) is NOT accepted: the
        // request reports the failure (500), never a bogus 202. The name's
        // other promise — "MarksFailed" — is pinned at the queue itself: the
        // row minted before dispatch must land FAILED, not linger PENDING. A
        // neutered markFailed arm strands a pending row (the reconcile
        // early-exits for pending rows), which is exactly the queue-lies class
        // this step exists to kill.
        foreach (['FAIL', 'none'] as $reply) {
            $resultDir = sys_get_temp_dir() . '/phpdup-s08-reject-' . uniqid();
            mkdir($resultDir, 0700);
            try {
                $queue = new JobQueue();
                $app = new Application($queue, null, null, '/tmp/phpdup-s08-unused.sock', $resultDir);
                $app->workerAckProbe = $this->ackProbe($reply);

                $response = $app->handle('POST', '/jobs', '{"paths":["src"]}', []);
                $this->assertSame(500, $response['status'], "reply '{$reply}' must reject dispatch");
                $decoded = json_decode($response['body'], true);
                $this->assertIsArray($decoded);
                $this->assertArrayHasKey('error', $decoded);
                $this->assertArrayNotHasKey('job_id', $decoded, 'a rejected dispatch must not advertise a success id');

                // The queue row itself (the only row this app minted): FAILED,
                // with the honest dispatch reason — captured through the same
                // JobQueue the app mutated.
                $rows = $queue->list();
                $this->assertCount(1, $rows, "reply '{$reply}' leaves exactly the one minted row");
                $id = (string)array_key_first($rows);
                $this->assertSame(JobQueue::STATUS_FAILED, $rows[$id]['status'], "reply '{$reply}' must fail the minted row");
                $row = $queue->get($id);
                $this->assertIsArray($row);
                $this->assertSame(JobQueue::STATUS_FAILED, $row['status']);
                $this->assertStringContainsString('failed to dispatch to worker', (string)$row['error']);

                // And the list route reports it — the reconcile never touches
                // a terminal row, so the honest FAILED survives the read path.
                $list = json_decode($app->handle('GET', '/api/jobs', '', [])['body'], true);
                $this->assertIsArray($list);
                $this->assertSame(JobQueue::STATUS_FAILED, $list[$id]['status']);
            } finally {
                @rmdir($resultDir);
            }
        }
    }

    public function testListRouteReconcilesCompletedAsyncJobs(): void
    {
        // GET /api/jobs must reflect the result-file reconcile too, not just
        // the per-id route.
        $now = 4_000_000.0;
        $app = $this->makeAsyncApp($now);
        $resultDir = (new \ReflectionProperty(Application::class, 'resultDir'))->getValue($app);
        $app->workerAckProbe = $this->ackProbe('OK');

        $id = json_decode($app->handle('POST', '/jobs', '{"paths":["src"]}', [])['body'], true)['job_id'];
        file_put_contents($resultDir . '/' . $id . '.json', json_encode([
            'files' => 7, 'blocks' => 9, 'clusters' => 1, 'config' => [],
        ]));

        $list = json_decode($app->handle('GET', '/api/jobs', '', [])['body'], true);
        $this->assertSame(JobQueue::STATUS_COMPLETED, $list[$id]['status']);
    }

    public function testSyncAnalyzeHonoursServerCacheAndWorkerPolicyWithoutChangingResult(): void
    {
        // shell-032 sync leg: serial-by-default (cache-off) is the historic
        // posture, and opting into cache + parallel workers must not change
        // what the detector reports. The policy knob only affects HOW the scan
        // runs, never the result — proven by the two bodies being identical.
        $root = sys_get_temp_dir() . '/phpdup-s08-sync-' . uniqid();
        mkdir($root);
        file_put_contents($root . '/a.php', "<?php function f(){ \$x=1; \$y=2; echo \$x+\$y; }");
        $body = "<?php function g(){ \$p=1; \$q=2; echo \$p+\$q; }";
        file_put_contents($root . '/b.php', $body);
        $cwd = getcwd();
        chdir($root);
        try {
            $payload = json_encode(['paths' => ['a.php', 'b.php']]);

            $app = new Application(new JobQueue(), $root, null, null, null, false, 1);
            $response = $app->handle('POST', '/analyze', $payload, []);
            $this->assertSame(200, $response['status']);
            $serial = json_decode($response['body'], true);
            $this->assertIsArray($serial);
            $this->assertNotEmpty($serial);

            // Same request through a cache-on, parallel-opt-in app: still a
            // well-formed 200 report (the policy knob neither errors nor
            // empties the pipeline). Cache/workers change HOW it runs, and
            // legitimately change the per-run cache meta, so only the success
            // shape is pinned — not byte identity.
            $appCached = new Application(new JobQueue(), $root, null, null, null, true, 2);
            $cached = $appCached->handle('POST', '/analyze', $payload, []);
            $this->assertSame(200, $cached['status']);
            $this->assertIsArray(json_decode($cached['body'], true));
        } finally {
            chdir($cwd);
            @unlink($root . '/a.php');
            @unlink($root . '/b.php');
            @rmdir($root);
        }
    }


    // ------------------------------------------------------------------
    // shell-049: the RUN-frame read is bounded. An endless single line
    // from a direct socket peer is refused at the cap without ever being
    // buffered whole, and the worker keeps serving the next dispatch.
    // ------------------------------------------------------------------

    public function testWorkerRefusesOversizedRequestLineAtTheCap(): void
    {
        $cap = 64;
        [$client, $controller] = $this->makeWorkerPair();
        // 21-byte 'RUN <id> ' prefix + 65 payload bytes + newline: past the
        // cap, and deliberately no delimiter inside the cap+1 window.
        $frame = 'RUN 0123456789abcdef ' . str_repeat('x', $cap + 1) . "\n";
        $this->assertGreaterThan($cap, strlen(rtrim($frame, "\n")));

        $ack = $this->workerAckCapped($client, $controller, $frame, $cap);

        $this->assertSame('FAIL 0 request line too long', $ack);
        // The raw bytes of the endless line must never re-enter the frame:
        // refusal carries no id and echoes nothing.
        $this->assertStringNotContainsString('0123456789abcdef', $ack);
        $this->assertStringNotContainsString('xxxx', $ack);
        // A refused read never reaches the pipeline: nothing is written.
        $this->assertSame([], glob($this->workerResultDir . '/*') ?: []);
    }

    public function testRequestCapBoundaryAcceptsExactCapAndRefusesOneByteOver(): void
    {
        $atCap = 'RUN 0123456789abcdef not-json'; // valid id, doomed JSON
        $cap = strlen($atCap);

        // Exactly at the cap: admitted by the read bound, so processing
        // advances all the way to the JSON stage — the ack is the decode
        // failure (which echoes the validated id), not the size refusal.
        [$client, $controller] = $this->makeWorkerPair();
        $ack = $this->workerAckCapped($client, $controller, $atCap . "\n", $cap);
        $this->assertStringStartsWith('FAIL 0123456789abcdef invalid JSON', $ack);

        // One byte over the cap: refused at the bound itself.
        [$client2, $controller2] = $this->makeWorkerPair();
        $ack2 = $this->workerAckCapped($client2, $controller2, $atCap . "z\n", $cap);
        $this->assertSame('FAIL 0 request line too long', $ack2);
    }

    public function testWorkerStaysAliveAfterRefusingAnOversizedRequestLine(): void
    {
        // The cap protects one connection's read; it must not poison the
        // worker. Same worker object, second dispatch: the job-id stage
        // still answers (shell-022 regression screen riding along).
        $cap = 64;
        $handleClient = new \ReflectionMethod(AnalysisWorker::class, 'handleClient');
        $worker = new AnalysisWorker(
            '/tmp/phpdup-worker-defence-unused.sock',
            $this->workerResultDir,
            null,
            $cap
        );

        [$client1, $controller1] = $this->makeWorkerPair();
        fwrite($controller1, 'RUN 0123456789abcdef ' . str_repeat('x', $cap + 1) . "\n");
        try {
            $handleClient->invoke($worker, $client1);
        } catch (\Throwable $e) {
            $this->fail('handleClient must never throw: ' . $e->getMessage());
        }
        $ack1 = fgets($controller1);
        $this->assertSame('FAIL 0 request line too long', rtrim((string)$ack1, "\r\n"));

        [$client2, $controller2] = $this->makeWorkerPair();
        fwrite($controller2, "RUN ../../evil {}\n");
        try {
            $handleClient->invoke($worker, $client2);
        } catch (\Throwable $e) {
            $this->fail('handleClient must never throw: ' . $e->getMessage());
        }
        $ack2 = fgets($controller2);
        $this->assertSame('FAIL 0 invalid job id', rtrim((string)$ack2, "\r\n"));

        $this->assertSame([], glob($this->workerResultDir . '/*') ?: []);
    }

    public function testWorkerCapAlignsWithTheHttpBodyCeiling(): void
    {
        // One source of truth: the socket read bound is the HTTP ceiling
        // plus headroom, so an HTTP-admitted frame can never be refused
        // by the worker's own bound (21 bytes of 'RUN <16hex> ' prefix
        // ride inside the 1024-byte margin).
        $this->assertSame(Application::MAX_BODY_BYTES + 1024, AnalysisWorker::MAX_REQUEST_BYTES);
        $this->assertGreaterThan(strlen('RUN ') + 16 + 1, AnalysisWorker::MAX_REQUEST_BYTES - Application::MAX_BODY_BYTES);
        $this->assertSame(
            'FAIL 0 request line too long',
            rtrim(AnalysisWorker::REQUEST_TOO_LONG_ACK, "\n")
        );
    }

    // ------------------------------------------------------------------
    // once-S02 (php-dup-finder-once-002): buffered admission (alive busy
    // worker never false-500s), held-channel terminal events (the full
    // pending→running→done lifecycle and honest worker-death reports),
    // the deadline kept only as the wedge backstop, and the visible-note
    // capacity eviction through the HTTP surface.
    // ------------------------------------------------------------------

    public function testBufferedDispatchIsAdmittedPendingAndCompletesThroughChannelAckAndFile(): void
    {
        // The alive-busy-worker leg: the frame is written, nothing answers
        // inside the ack window (the worker is behind an earlier job). That
        // is an ADMISSION — a 202 with an honestly PENDING row, never a
        // false 500. The late accept-ack flips it to running; the result
        // file flips it to completed. Full lifecycle, observed via
        // GET /jobs/<id>.
        $now = 6_000_000.0;
        $app = $this->makeAsyncApp($now);
        $resultDir = (new \ReflectionProperty(Application::class, 'resultDir'))->getValue($app);

        $app->workerAckProbe = static function (string $socketPath, string $message): string {
            return ''; // frame written; busy worker answered nothing
        };
        /** @var list<array{kind:string, reason?:string}> $events */
        $events = [];
        $app->workerChannelProbe = static function () use (&$events): ?array {
            return array_shift($events);
        };

        $response = $app->handle('POST', '/jobs', '{"paths":["src"]}', []);
        $this->assertSame(202, $response['status'], 'a live worker cannot answer yet is an admission, not a failure');
        $id = json_decode($response['body'], true)['job_id'];

        $poll = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
        $this->assertSame(JobQueue::STATUS_PENDING, $poll['status']);

        // Channel says the worker has now read and accepted the frame.
        $events[] = ['kind' => 'ack'];
        $poll = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
        $this->assertSame(JobQueue::STATUS_RUNNING, $poll['status']);

        // …runs… and publishes.
        file_put_contents($resultDir . '/' . $id . '.json', json_encode([
            'files' => 5, 'blocks' => 6, 'clusters' => 1, 'config' => [],
        ]));
        $poll = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
        $this->assertSame(JobQueue::STATUS_COMPLETED, $poll['status']);
        $this->assertSame(5, $poll['result']['files']);
    }

    public function testChannelGoneFailsTheJobAsObservedDeathNotTimeoutLie(): void
    {
        // The worker died: the held channel closed and no result file
        // landed. The very next poll must report the death as an observed
        // fact — not wait out, or blame, the absence-deadline.
        $now = 7_000_000.0;
        $app = $this->makeAsyncApp($now);
        $app->asyncResultDeadlineSeconds = 300;
        $app->workerAckProbe = $this->ackProbe('OK');
        $app->workerChannelProbe = static function (): ?array {
            return ['kind' => 'gone'];
        };

        $id = json_decode($app->handle('POST', '/jobs', '{"paths":["src"]}', [])['body'], true)['job_id'];

        $poll = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
        $this->assertSame(JobQueue::STATUS_FAILED, $poll['status']);
        $this->assertStringContainsString('ended without publishing', $poll['error']);
        $this->assertStringNotContainsString('did not produce a result', $poll['error']);

        // Terminal rows are settled: even advancing past the deadline
        // leaves the honest death message untouched.
        $now += 400.0;
        $poll = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
        $this->assertStringContainsString('ended without publishing', $poll['error']);
    }

    public function testChannelErrFrameFailsWithTheWorkersOwnReason(): void
    {
        // A worker that accepts, then fails the pipeline, publishes no file
        // and sends `ERR <id> <reason>`. The reconcile surfaces the frame's
        // reason on the row instead of leaving it running-until-deadline.
        $now = 8_000_000.0;
        $app = $this->makeAsyncApp($now);
        $app->workerAckProbe = $this->ackProbe('OK');
        $app->workerChannelProbe = static function (): ?array {
            return ['kind' => 'fail', 'reason' => 'worker reported failure: extraction blew up'];
        };

        $id = json_decode($app->handle('POST', '/jobs', '{"paths":["src"]}', [])['body'], true)['job_id'];

        $poll = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
        $this->assertSame(JobQueue::STATUS_FAILED, $poll['status']);
        $this->assertStringContainsString('extraction blew up', $poll['error']);
    }

    public function testWedgedChannelStillFallsBackToTheAbsenceDeadline(): void
    {
        // The deadline is the backstop, not the headline: a channel that
        // stays open yet silent (a wedged worker) still settles honestly
        // past the window. Before it, "still" must not change anything.
        $now = 9_000_000.0;
        $app = $this->makeAsyncApp($now);
        $app->asyncResultDeadlineSeconds = 300;
        $app->workerAckProbe = $this->ackProbe('OK');
        $app->workerChannelProbe = static function (): array {
            return ['kind' => 'still'];
        };

        $id = json_decode($app->handle('POST', '/jobs', '{"paths":["src"]}', [])['body'], true)['job_id'];

        $now += 100.0;
        $this->assertSame(
            JobQueue::STATUS_RUNNING,
            json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true)['status']
        );

        $now += 400.0;
        $poll = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
        $this->assertSame(JobQueue::STATUS_FAILED, $poll['status']);
        $this->assertStringContainsString('did not produce a result', $poll['error']);
    }

    public function testLateAckPastWindowStaysRunningAndNextSilentPollStillFails(): void
    {
        // once-S02 r1 MEDIUM-1 pin: a late ack is evidence of life — the
        // opposite of the silence the absence-deadline polices. A buffered
        // admission whose first observation happens past the deadline (deep
        // worker backlog) must flip to RUNNING, not be false-failed by the
        // backstop the ack arm used to fall through into. And the cure must
        // not mask genuine wedges: asyncDispatchedAt is deliberately NOT
        // re-anchored on ack, so the next still-silent reconcile of the
        // same row still hits the window (second leg).
        $now = 11_000_000.0;
        $app = $this->makeAsyncApp($now);
        $app->asyncResultDeadlineSeconds = 300;
        $app->workerAckProbe = static function (string $socketPath, string $message): string {
            return ''; // frame written; busy worker answered nothing
        };
        /** @var list<array{kind:string, reason?:string}> $events */
        $events = [];
        $app->workerChannelProbe = static function () use (&$events): ?array {
            return array_shift($events);
        };

        $response = $app->handle('POST', '/jobs', '{"paths":["src"]}', []);
        $this->assertSame(202, $response['status']);
        $id = json_decode($response['body'], true)['job_id'];

        // Deep backlog: the whole deadline window elapses before the first
        // read of the held channel — the ack arrives already overdue.
        $now += 1_000.0;
        $events[] = ['kind' => 'ack'];

        $poll = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
        $this->assertSame(JobQueue::STATUS_RUNNING, $poll['status'], 'an ack observed past the window is life, not a timeout');

        // Non-masking leg: the channel goes silent again on the next
        // reconcile — the row must still settle via the backstop.
        $events[] = ['kind' => 'still'];

        $poll = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
        $this->assertSame(JobQueue::STATUS_FAILED, $poll['status']);
        $this->assertStringContainsString('did not produce a result', $poll['error']);
    }

    public function testRealHeldChannelObservesLateAckThenCompletionOverASocket(): void
    {
        // Seam-free transport leg: a real unix listener accepts our
        // dispatch, does not answer inside the (seamed, 1 s) ack window —
        // buffered admission — and the KEPT-OPEN channel then delivers the
        // late `OK` line and, after the file lands, is closed and forgotten.
        $sockPath = sys_get_temp_dir() . '/phpdup-once-s02-' . uniqid('', true) . '.sock';
        $listener = @stream_socket_server('unix://' . $sockPath, $errno, $errstr);
        $this->assertIsResource($listener, "listener needed for the real-channel leg: {$errstr}");
        $resultDir = sys_get_temp_dir() . '/phpdup-once-s02-results-' . uniqid('', true);
        mkdir($resultDir, 0700);

        try {
            $app = new Application(new JobQueue(), null, null, $sockPath, $resultDir);
            $app->dispatchAckTimeoutSeconds = 1;

            $start = microtime(true);
            $response = $app->handle('POST', '/jobs', '{"paths":["src"]}', []);
            $elapsed = microtime(true) - $start;

            $this->assertSame(202, $response['status'], 'a busy live worker must not produce a false 500');
            $this->assertLessThan(4.0, $elapsed, 'the seamed 1 s ack window governs, not the 5 s default');
            $id = json_decode($response['body'], true)['job_id'];

            $poll = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
            $this->assertSame(JobQueue::STATUS_PENDING, $poll['status']);

            // Play the worker: accept, read the RUN frame, answer late.
            $client = @stream_socket_accept($listener, 5);
            $this->assertIsResource($client);
            $frame = @stream_get_line($client, 65536, "\n");
            $this->assertIsString($frame);
            $this->assertStringStartsWith("RUN {$id} ", $frame);
            $this->assertStringEndsWith('{"paths":["src"]}', $frame);

            @fwrite($client, "OK {$id}\n");
            $poll = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
            $this->assertSame(JobQueue::STATUS_RUNNING, $poll['status'], 'the late ack on the held channel flips pending→running');

            file_put_contents($resultDir . '/' . $id . '.json', json_encode([
                'files' => 3, 'blocks' => 4, 'clusters' => 2, 'config' => [],
            ]));
            @fclose($client);

            // File-first: a success-close must never read as worker death.
            $poll = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
            $this->assertSame(JobQueue::STATUS_COMPLETED, $poll['status']);
            $this->assertStringNotContainsString('ended without publishing', (string) $poll['error']);

            // Settling forgets the channel and the deadline anchor.
            $this->assertSame([], (new \ReflectionProperty(Application::class, 'asyncChannels'))->getValue($app));
            $this->assertSame([], (new \ReflectionProperty(Application::class, 'asyncDispatchedAt'))->getValue($app));
        } finally {
            if (is_resource($client ?? null)) {
                @fclose($client);
            }
            @fclose($listener);
            @unlink($sockPath);
            foreach (glob($resultDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($resultDir);
        }
    }

    public function testRealHeldChannelReportsDeathWhenWorkerHangsUpMidJob(): void
    {
        // Seam-free death leg: the real channel answers `OK`, then the
        // worker dies with no result file. The first poll drains the late
        // ack line (pending→running); the second observes the hang-up and
        // fails the row with the observed-death wording — the clock is
        // nowhere near the absence-deadline, so this is a fact, not a
        // timeout story.
        $sockPath = sys_get_temp_dir() . '/phpdup-once-s02-' . uniqid('', true) . '.sock';
        $listener = @stream_socket_server('unix://' . $sockPath, $errno, $errstr);
        $this->assertIsResource($listener, "listener needed for the real-death leg: {$errstr}");
        $resultDir = sys_get_temp_dir() . '/phpdup-once-s02-results-' . uniqid('', true);
        mkdir($resultDir, 0700);

        try {
            $app = new Application(new JobQueue(), null, null, $sockPath, $resultDir);
            $app->dispatchAckTimeoutSeconds = 1;

            $response = $app->handle('POST', '/jobs', '{"paths":["src"]}', []);
            $this->assertSame(202, $response['status']);
            $id = json_decode($response['body'], true)['job_id'];

            $client = @stream_socket_accept($listener, 5);
            $this->assertIsResource($client);
            $frame = @stream_get_line($client, 65536, "\n");
            $this->assertIsString($frame);
            $this->assertStringStartsWith("RUN {$id} ", $frame);
            @fwrite($client, "OK {$id}\n");
            @fclose($client); // crash after the ack, before publishing

            $first = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
            $this->assertSame(JobQueue::STATUS_RUNNING, $first['status'], 'the held channel still had the OK line to drain');

            $second = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
            $this->assertSame(JobQueue::STATUS_FAILED, $second['status']);
            $this->assertStringContainsString('ended without publishing', (string) $second['error']);
            $this->assertStringNotContainsString('did not produce a result', (string) $second['error']);

            $this->assertSame([], (new \ReflectionProperty(Application::class, 'asyncChannels'))->getValue($app));
        } finally {
            if (is_resource($client ?? null)) {
                @fclose($client);
            }
            @fclose($listener);
            @unlink($sockPath);
            foreach (glob($resultDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($resultDir);
        }
    }

    public function testCapEvictionIsVisibleInSubmitBodyAndOnPollingTheVictim(): void
    {
        // The queue is at cap with 100 pending rows; this submission pays
        // for its admission with the oldest row — and says so. Polling the
        // victim returns a 404 that names the eviction, not a bare "not
        // found".
        $queue = new JobQueue();
        $victims = [];
        for ($i = 0; $i < JobQueue::MAX_JOBS; $i++) {
            $victims[$i] = $queue->enqueue(['index' => $i]);
        }

        $resultDir = sys_get_temp_dir() . '/phpdup-once-s02-cap-' . uniqid('', true);
        mkdir($resultDir, 0700);
        try {
            $app = new Application($queue, null, null, '/tmp/phpdup-once-s02-unused.sock', $resultDir);
            $app->workerAckProbe = $this->ackProbe('OK');

            $response = $app->handle('POST', '/jobs', '{"paths":["src"]}', []);
            $this->assertSame(202, $response['status']);
            $decoded = json_decode($response['body'], true);
            $this->assertArrayHasKey('job_id', $decoded);
            $this->assertArrayHasKey('evicted', $decoded, 'the submitter sees what its admission displaced');
            $this->assertCount(1, $decoded['evicted']);
            $this->assertSame($victims[0], $decoded['evicted'][0]['job_id']);
            $this->assertSame(JobQueue::STATUS_PENDING, $decoded['evicted'][0]['status']);
            $this->assertStringContainsString('at capacity', $decoded['evicted'][0]['reason']);

            $poll = $app->handle('GET', '/jobs/' . $victims[0], '', []);
            $this->assertSame(404, $poll['status']);
            $this->assertStringContainsString('capacity', json_decode($poll['body'], true)['error']);

            $api = $app->handle('GET', '/api/jobs/' . $victims[0], '', []);
            $this->assertSame(404, $api['status']);
            $this->assertStringContainsString('capacity', json_decode($api['body'], true)['error']);

            // An id the queue never evicted keeps the plain story.
            $plain = $app->handle('GET', '/jobs/deadbeefdeadbeef', '', []);
            $this->assertSame(404, $plain['status']);
            $this->assertSame('job not found', json_decode($plain['body'], true)['error']);
        } finally {
            @rmdir($resultDir);
        }
    }

    public function testRejectedDispatchStillReportsTheEvictionsItCaused(): void
    {
        // Even the honest 500 tells the whole truth: the room the rejected
        // submission displaced is in the body too (the failed row itself
        // stays queued, per shell-S08 fix1).
        $queue = new JobQueue();
        $victims = [];
        for ($i = 0; $i < JobQueue::MAX_JOBS; $i++) {
            $victims[$i] = $queue->enqueue(['index' => $i]);
        }

        $resultDir = sys_get_temp_dir() . '/phpdup-once-s02-rej-' . uniqid('', true);
        mkdir($resultDir, 0700);
        try {
            $app = new Application($queue, null, null, '/tmp/phpdup-once-s02-unused.sock', $resultDir);
            $app->workerAckProbe = $this->ackProbe('none');

            $response = $app->handle('POST', '/jobs', '{"paths":["src"]}', []);
            $this->assertSame(500, $response['status']);
            $decoded = json_decode($response['body'], true);
            $this->assertArrayNotHasKey('job_id', $decoded);
            $this->assertSame($victims[0], $decoded['evicted'][0]['job_id']);
        } finally {
            @rmdir($resultDir);
        }
    }

    public function testOrdinaryTwentyTwoCarriesNoEvictionKey(): void
    {
        // Additive shape: under no cap pressure the 202 body stays byte-
        // identical to the pre-once-S02 contract.
        $now = 10_000_000.0;
        $app = $this->makeAsyncApp($now);
        $app->workerAckProbe = $this->ackProbe('OK');

        $response = $app->handle('POST', '/jobs', '{"paths":["src"]}', []);
        $this->assertSame(202, $response['status']);
        $this->assertSame(['job_id'], array_keys(json_decode($response['body'], true)));
    }

    // ------------------------------------------------------------------
    // once-S03 (php-dup-finder-once-003 + once-065): the job-row summary is
    // read from the report's nested `summary` block — not the root level,
    // where the counters never lived — so a settled row shows the same
    // numbers `--json` reports; and `/analyze` + the worker take their
    // clustering posture from the payload's optional `exact_only` key
    // instead of hard-forcing exact-only, so the full-report promise holds
    // on every transport.
    // ------------------------------------------------------------------

    /** Absolute repo root (tests/Unit/Server → three levels up). */
    private function repoRoot(): string
    {
        $root = realpath(dirname(__DIR__, 3));
        $this->assertIsString($root);
        return $root;
    }

    public function testSyncJobSummaryReportsRealCountersAtTheReportSummaryLevel(): void
    {
        // once-003 CRITICAL: a worker-less job over the real notify fixture
        // must land a completed row whose summary shows what the analysis
        // found — the historic buildSummary read the payload ROOT level,
        // where files/blocks do not exist and `clusters` is a list, so every
        // row was {0,0,0} no matter the result.
        $root = $this->repoRoot();
        $cwd  = getcwd();
        chdir($root);
        try {
            $payload = json_encode(['paths' => ['tests/Fixtures/notify']]);
            $app = new Application(new JobQueue(), $root, null);

            $response = $app->handle('POST', '/jobs', $payload, []);
            $this->assertSame(202, $response['status']);
            $id = json_decode($response['body'], true)['job_id'];

            $row = json_decode($app->handle('GET', '/jobs/' . $id, '', [])['body'], true);
            $this->assertSame(JobQueue::STATUS_COMPLETED, $row['status']);
            $summary = $row['result'];
            $this->assertIsArray($summary);
            $this->assertGreaterThan(0, $summary['files'], 'the row names the files scanned, not zero');
            $this->assertGreaterThan(0, $summary['blocks'], 'the row names the blocks extracted, not zero');
            $this->assertGreaterThan(0, $summary['clusters'], 'the row names the clusters found, not zero');

            // Same counters the report itself carries: the queue row and the
            // `--json` surface can never disagree about what the run did.
            $report = json_decode($app->handle('POST', '/analyze', $payload, [])['body'], true);
            $this->assertSame($report['summary']['files'], $summary['files']);
            $this->assertSame($report['summary']['blocks'], $summary['blocks']);
            $this->assertSame($report['summary']['clusters'], $summary['clusters']);
        } finally {
            chdir($cwd);
        }
    }

    public function testSyncAnalyzeCarriesNearDupHolesAndSignatureFields(): void
    {
        // once-065 MEDIUM, sync leg: `/analyze` used to force exact-only, so
        // the optional fixture (a parameterizable near-dup pair) came back
        // with NO clusters at all while SERVER.md promised the full report.
        // Default posture is now the full analysis: the near-dup survives,
        // and the payload carries the extraction-evidence keys.
        $root = $this->repoRoot();
        $cwd  = getcwd();
        chdir($root);
        try {
            $response = (new Application(new JobQueue(), $root, null))
                ->handle('POST', '/analyze', json_encode(['paths' => ['tests/Fixtures/optional']]), []);
            $this->assertSame(200, $response['status']);
            $decoded = json_decode($response['body'], true);
            $this->assertIsArray($decoded);
            $this->assertSame(1, $decoded['summary']['clusters'], 'the near-duplicate cluster is no longer dropped');
            $this->assertCount(1, $decoded['clusters']);

            $cluster = $decoded['clusters'][0];
            $this->assertFalse($cluster['exact'], 'the surviving cluster is the near-dup, not an exact twin');
            $this->assertArrayHasKey('holes', $cluster);
            $this->assertIsArray($cluster['holes']);
            $this->assertNotEmpty($cluster['holes'], 'parameterizable holes ride in the payload');
            $this->assertArrayHasKey('signature', $cluster);
            $this->assertIsString($cluster['signature']);
            $this->assertNotSame('', $cluster['signature']);
        } finally {
            chdir($cwd);
        }
    }

    public function testExactOnlyTruePayloadIsHonouredNotIgnored(): void
    {
        // The posture key is CONSUMED, not just tolerated: asking for
        // exact-only on the near-dup-only fixture really does short-circuit
        // (and the same payload without the key really did run the full
        // analysis — the pair pins both directions of the lever).
        $root = $this->repoRoot();
        $cwd  = getcwd();
        chdir($root);
        try {
            $response = (new Application(new JobQueue(), $root, null))->handle(
                'POST',
                '/analyze',
                json_encode(['paths' => ['tests/Fixtures/optional'], 'exact_only' => true]),
                []
            );
            $this->assertSame(200, $response['status']);
            $decoded = json_decode($response['body'], true);
            $this->assertSame(0, $decoded['summary']['clusters']);
            $this->assertSame([], $decoded['clusters']);
        } finally {
            chdir($cwd);
        }
    }

    public function testMistypedExactOnlyRefusedAtHttpWithNoJobMinted(): void
    {
        // Presence decides, value-typed — the refused-keys discipline riding
        // onto the optional posture key: a string, an int or an explicit
        // null is a shape violation answered 400 BEFORE any row exists, so
        // it can never surface later as a dispatched-but-wrong job.
        // Serve root stays null on purpose: the paths leg must not be the
        // one answering these requests — the refusal names the posture key,
        // and no fixture/cwd dependence leaks into a pure admission pin.
        $queue = new JobQueue();
        $app   = new Application($queue, null, null);

        $analyze = $app->handle('POST', '/analyze', '{"paths":["src"],"exact_only":"yes"}', []);
        $this->assertSame(400, $analyze['status']);
        $this->assertStringContainsString('exact_only must be a boolean', $analyze['body']);

        $jobs = $app->handle('POST', '/jobs', '{"paths":["src"],"exact_only":1}', []);
        $this->assertSame(400, $jobs['status']);
        $this->assertStringContainsString('exact_only must be a boolean', $jobs['body']);

        $nulls = $app->handle('POST', '/jobs', '{"paths":["src"],"exact_only":null}', []);
        $this->assertSame(400, $nulls['status']);

        // A mistyped posture, unlike an untyped path violation, must have
        // minted nothing: the queue stays byte-empty.
        $this->assertSame([], json_decode($app->handle('GET', '/api/jobs', '', [])['body'], true));
    }

    public function testExactOnlyRidesTheAsyncDispatchFrameVerbatim(): void
    {
        // Transport parity, wire side: the admitted posture travels to the
        // worker inside the RUN frame's JSON (scalars-only wire law holds —
        // the payload crosses as one JSON string), so the async leg runs the
        // very posture the HTTP layer parsed.
        $now = 11_000_000.0;
        $app = $this->makeAsyncApp($now);
        $seen = null;
        $app->workerAckProbe = static function (string $socketPath, string $message) use (&$seen): ?string {
            $seen = $message;
            $id = explode(' ', $message)[1] ?? '';
            return "OK {$id}";
        };

        $response = $app->handle(
            'POST',
            '/jobs',
            json_encode(['paths' => ['src'], 'exact_only' => true]),
            []
        );
        $this->assertSame(202, $response['status']);
        $this->assertIsString($seen);
        $this->assertStringStartsWith('RUN ', $seen);
        $this->assertStringContainsString('"exact_only":true', $seen);
    }

    public function testWorkerAdmissionGatesMistypedExactOnlyAndAcceptsBools(): void
    {
        // Decision leg, isolated like the ml-pair gate pins: the worker's
        // RUN-frame admission refuses a mistyped posture with the shared
        // verdict (prefixed so the wire names its own layer) and admits
        // true, false, and absent.
        $root = sys_get_temp_dir() . '/phpdup-s03-admit-' . uniqid();
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
            $admit = new \ReflectionMethod(AnalysisWorker::class, 'admitFrame');

            $refused = $admit->invoke($worker, ['paths' => ['a.php'], 'exact_only' => 'yes']);
            $this->assertSame(400, $refused['status']);
            $this->assertStringContainsString('RUN frame exact_only must be a boolean', $refused['error']);

            $this->assertNull($admit->invoke($worker, ['paths' => ['a.php'], 'exact_only' => true]));
            $this->assertNull($admit->invoke($worker, ['paths' => ['a.php'], 'exact_only' => false]));
            $this->assertNull($admit->invoke($worker, ['paths' => ['a.php']]));
        } finally {
            chdir($cwd);
            @unlink($root . '/a.php');
            @rmdir($root);
        }
    }

    public function testWorkerHonoursExactOnlyPostureOverTheWire(): void
    {
        // once-065, worker leg end-to-end: the same confined frame bytes the
        // HTTP layer forwards run under the posture the frame names — full
        // analysis by default (near-dup cluster with holes + signature in
        // the published file), short-circuited under exact_only true, and a
        // mistyped posture never reaches the pipeline at all.
        $root = $this->repoRoot();
        $cwd  = getcwd();
        chdir($root);
        try {
            $handleClient = new \ReflectionMethod(AnalysisWorker::class, 'handleClient');

            // Frame 1: no posture key → full report lands.
            [$client, $controller] = $this->makeWorkerPair();
            fwrite($controller, "RUN 0000000000000001 {\"paths\":[\"tests/Fixtures/optional\"]}\n");
            $handleClient->invoke(new AnalysisWorker(
                '/tmp/phpdup-worker-defence-unused.sock',
                $this->workerResultDir,
                $root
            ), $client);
            $this->assertSame('OK 0000000000000001', rtrim((string)fgets($controller), "\r\n"));
            $full = json_decode((string)file_get_contents($this->workerResultDir . '/0000000000000001.json'), true);
            $this->assertIsArray($full);
            $this->assertSame(1, $full['summary']['clusters'], 'the worker no longer forces exact-only away from the full report');
            $this->assertNotEmpty($full['clusters'][0]['holes']);
            $this->assertIsString($full['clusters'][0]['signature']);

            // Frame 2: exact_only true → honoured short-circuit.
            [$client2, $controller2] = $this->makeWorkerPair();
            fwrite($controller2, "RUN 0000000000000002 {\"paths\":[\"tests/Fixtures/optional\"],\"exact_only\":true}\n");
            $handleClient->invoke(new AnalysisWorker(
                '/tmp/phpdup-worker-defence-unused.sock',
                $this->workerResultDir,
                $root
            ), $client2);
            $this->assertSame('OK 0000000000000002', rtrim((string)fgets($controller2), "\r\n"));
            $forced = json_decode((string)file_get_contents($this->workerResultDir . '/0000000000000002.json'), true);
            $this->assertSame(0, $forced['summary']['clusters']);

            // Frame 3: mistyped posture → FAIL, no OK, no file.
            [$client3, $controller3] = $this->makeWorkerPair();
            fwrite($controller3, "RUN 0000000000000003 {\"paths\":[\"tests/Fixtures/optional\"],\"exact_only\":\"yes\"}\n");
            $handleClient->invoke(new AnalysisWorker(
                '/tmp/phpdup-worker-defence-unused.sock',
                $this->workerResultDir,
                $root
            ), $client3);
            $ack = rtrim((string)fgets($controller3), "\r\n");
            $this->assertStringStartsWith('FAIL 0000000000000003 RUN frame exact_only must be a boolean', $ack);
            $this->assertFileDoesNotExist($this->workerResultDir . '/0000000000000003.json');
        } finally {
            chdir($cwd);
        }
    }
}
