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

    /** Entry CWD captured by the once-S93 chdir-leak fence. */
    private string $s93EntryCwd = '';

    protected function setUp(): void
    {
        // once-S93 (once-116 'ApplicationTest restores CWD'): fence the
        // entry working directory before any arm can move. The escape
        // arms chdir on purpose and the live-serve legs boot children
        // with their own cwd; a LEAKED chdir silently redefines '.' for
        // every later test in the process, so the class promises — and
        // now proves — that it leaves as it found.
        $entry = getcwd();
        $this->assertNotFalse($entry, 'entry CWD is unreadable — this class needs a working directory');
        $this->s93EntryCwd = $entry;

        $this->workerResultDir = sys_get_temp_dir() . '/phpdup-s02-' . uniqid() . '-results';
        mkdir($this->workerResultDir, 0700);
    }

    protected function tearDown(): void
    {
        // Restore (a leak must not poison siblings), but the VERDICT is
        // computed from the as-found value — asserting on the repaired
        // CWD could never fail, and that is the vacuity class this step
        // removes.
        if ($this->s93EntryCwd !== '') {
            $now = getcwd();
            $leaked = $now !== $this->s93EntryCwd;
            if ($leaked) {
                @chdir($this->s93EntryCwd);
            }
            $this->assertFalse(
                $leaked,
                'an arm in this file leaked its working directory (once-S93 chdir fence); leaked to: '
                    . var_export($now, true)
            );
        }

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

    // ------------------------------------------------------------------
    // once-S94: the async serve surface, end to end over REAL HTTP. The
    // legs above drive the dispatcher through probe seams and a hand-played
    // unix channel; this battery boots the real `phpdup serve` daemon as a
    // child process on a kernel-picked ephemeral 127.0.0.1 port (the
    // port-0 occupation probe is the ServeCommandSweepTest convention),
    // lets its REAL spawned worker do the analysis, and speaks raw HTTP/1.1
    // at it. Every async route is answered at least once for real; the
    // killed-worker leg proves the queue can never lie about a dead worker;
    // the trust/413 legs prove the once-046 gate and the transport body
    // cap are actually WIRED into the accept loop, not just defined.
    // All waits are poll-until-deadline (no fixed sleeps); every child is
    // this test's own and is verified unreachable before the test returns.
    // ------------------------------------------------------------------

    public function testLiveServeAsyncHappyPathEndToEndWithRealResultBytes(): void
    {
        $this->s94RequirePosixSupervision();
        $root = $this->repoRoot();
        $serve = $this->s94SpawnServe($root);
        try {
            $health = $this->s94Http($serve['port'], 'GET', '/healthz');
            $this->assertSame(200, $health['status']);
            $this->assertSame('ok', $health['body']);

            // The headline async admission over the wire.
            $submit = $this->s94Http($serve['port'], 'POST', '/jobs', '{"paths":["tests/Fixtures/notify"]}');
            $this->assertSame(202, $submit['status'], 'live async POST /jobs must answer 202: ' . $submit['body']);
            $decodedSubmit = json_decode($submit['body'], true);
            $this->assertSame(['job_id'], array_keys($decodedSubmit), 'ordinary 202 shape survives the live transport');
            $id = (string) $decodedSubmit['job_id'];

            // Poll the status route until the row is terminal. Every
            // intermediate read is a live-state observation; the verdict is
            // COMPLETED — the real worker really ran the real pipeline.
            [$observed, $row] = $this->s94PollUntil(
                fn(): array => (array) json_decode($this->s94Http($serve['port'], 'GET', '/jobs/' . $id)['body'], true),
                static fn(array $r): bool => ($r['status'] ?? '') === JobQueue::STATUS_COMPLETED
                    || ($r['status'] ?? '') === JobQueue::STATUS_FAILED,
                60.0,
                'async job over live serve to reach a terminal state'
            );
            foreach ($observed as $read) {
                $this->assertContains((string) ($read['status'] ?? ''), [
                    JobQueue::STATUS_PENDING, JobQueue::STATUS_RUNNING,
                    JobQueue::STATUS_COMPLETED, JobQueue::STATUS_FAILED,
                ], '/jobs/{id} must only ever report a real queue status');
            }
            $this->assertSame(JobQueue::STATUS_COMPLETED, $row['status'], 'observed: ' . json_encode(array_column($observed, 'status')));

            // The status-only route agrees with the row route.
            $statusRoute = $this->s94Http($serve['port'], 'GET', '/api/jobs/' . $id);
            $this->assertSame(200, $statusRoute['status']);
            $this->assertSame(JobQueue::STATUS_COMPLETED, json_decode($statusRoute['body'], true)['status']);

            // REAL result bytes: the worker's published report, served from
            // the results directory, with non-zero counters at the report
            // summary level (the once-003 cure, live).
            $result = $this->s94Http($serve['port'], 'GET', '/api/jobs/' . $id . '/result');
            $this->assertSame(200, $result['status']);
            $this->assertSame('application/json', $result['headers']['content-type']);
            $report = json_decode($result['body'], true);
            $this->assertIsArray($report);
            $this->assertGreaterThan(0, $report['summary']['files']);
            $this->assertGreaterThan(0, $report['summary']['blocks']);
            $this->assertGreaterThan(0, $report['summary']['clusters']);

            // The settled row and the served bytes can never disagree —
            // one shared settlement helper, now proven across the transport.
            $this->assertSame($report['summary']['files'], $row['result']['files']);
            $this->assertSame($report['summary']['blocks'], $row['result']['blocks']);
            $this->assertSame($report['summary']['clusters'], $row['result']['clusters']);

            // The list route: keyed by the id, carrying exactly the
            // descriptor shape (payload bytes never surface over HTTP).
            $list = $this->s94Http($serve['port'], 'GET', '/api/jobs');
            $this->assertSame(200, $list['status']);
            $rows = json_decode($list['body'], true);
            $this->assertIsArray($rows);
            $this->assertArrayHasKey($id, $rows);
            $this->assertSame(['status', 'created_at', 'completed_at'], array_keys($rows[$id]));
            $this->assertSame(JobQueue::STATUS_COMPLETED, $rows[$id]['status']);

            // An id this daemon never minted keeps the plain story on both
            // per-id shapes (16 hex chars, same alphabet as minted ids).
            $never = bin2hex(random_bytes(8));
            $gone = $this->s94Http($serve['port'], 'GET', '/jobs/' . $never);
            $this->assertSame(404, $gone['status']);
            $this->assertSame('job not found', json_decode($gone['body'], true)['error']);

            // S03 over the live wire, both directions: default posture keeps
            // the near-dup (holes + signature in the payload), and the
            // optional key really short-circuits. A mistyped posture is a
            // pre-mint 400, not a dispatched-but-wrong job.
            $analyze = $this->s94Http($serve['port'], 'POST', '/analyze', '{"paths":["tests/Fixtures/optional"]}');
            $this->assertSame(200, $analyze['status']);
            $near = json_decode($analyze['body'], true);
            $this->assertSame(1, $near['summary']['clusters'], 'the full report posture rides live HTTP');
            $this->assertNotEmpty($near['clusters'][0]['holes']);

            $exact = $this->s94Http($serve['port'], 'POST', '/analyze', '{"paths":["tests/Fixtures/optional"],"exact_only":true}');
            $this->assertSame(200, $exact['status']);
            $this->assertSame(0, json_decode($exact['body'], true)['summary']['clusters']);

            $mistyped = $this->s94Http($serve['port'], 'POST', '/analyze', '{"paths":["tests/Fixtures/optional"],"exact_only":"yes"}');
            $this->assertSame(400, $mistyped['status']);
            $this->assertStringContainsString('exact_only must be a boolean', $mistyped['body']);
        } finally {
            $this->s94StopServe($serve);
        }
    }

    public function testLiveServeWorkerKilledMidQueueFailsEveryLiveRowAsObservedDeath(): void
    {
        $this->s94RequirePosixSupervision();
        $root = $this->repoRoot();
        $serve = $this->s94SpawnServe($root);
        try {
            // Job 1: 'src' is a multi-second real analysis at tip (measured
            // 10.9 s serial) — it holds the worker for the whole leg.
            $first = $this->s94Http($serve['port'], 'POST', '/jobs', '{"paths":["src"]}');
            $this->assertSame(202, $first['status']);
            $id1 = (string) json_decode($first['body'], true)['job_id'];

            // Job 2: the busy worker never reads this frame — the dispatch
            // is a BUFFERED admission, honestly pending, channel held.
            $second = $this->s94Http($serve['port'], 'POST', '/jobs', '{"paths":["src"]}');
            $this->assertSame(202, $second['status'], 'a busy live worker is an admission, not a 500: ' . $second['body']);
            $id2 = (string) json_decode($second['body'], true)['job_id'];

            // Kill-time census: read every row ONCE. A row that is already
            // terminal must be honestly completed with real counters (the
            // machine was fast, not lying); rows still live join the set the
            // kill is about to condemn. The buffered admission is ALWAYS in
            // that set: its POST returned at the end of the 5 s ack window
            // and the real src job takes seconds more than the milliseconds
            // until the read below.
            /** @var list<string> $liveAtKill */
            $liveAtKill = [];
            foreach ([$id1, $id2] as $id) {
                $row = json_decode($this->s94Http($serve['port'], 'GET', '/jobs/' . $id)['body'], true);
                if (in_array($row['status'], [JobQueue::STATUS_PENDING, JobQueue::STATUS_RUNNING], true)) {
                    $liveAtKill[] = $id;
                } else {
                    $this->assertSame(JobQueue::STATUS_COMPLETED, $row['status'], 'pre-kill terminal read: ' . json_encode($row));
                    $this->assertGreaterThan(0, (int) ($row['result']['files'] ?? 0), 'a row completed before the kill still shows real counters');
                }
            }
            $this->assertNotEmpty($liveAtKill, 'at least the buffered job must be live at kill time');

            // kill -9 the REAL analysis process. The pid printed on the
            // daemon's 'worker spawned (pid N)' line is N = proc_get_status
            // of the string-command spawn — when PHP's shell wrapper keeps
            // its own pid (proc_open string-command posture, probed at this
            // era), killing only N leaves the actual worker analyzing. The
            // kill leg therefore identifies the process by what it holds:
            // the daemon-unique worker socket path in its argv.
            //
            // once-S93 (once-116 'Server kill-setup' load-flake family):
            // the old arm did ONE liveness census and then asserted each
            // posix_kill(9) returned true. A worker exiting on its own
            // between the census and the signal made a perfectly healthy
            // contract fail as 'test setup', and an empty census (the
            // per-job worker churn landing in the same instant) failed
            // the premise outright. Both halves are now OBSERVED, bounded
            // facts, and the leg stands or falls on the end state it is
            // here to create — every census pid provably dead. A timeout
            // never launders into success: each window below fails loud
            // with everything it saw.
            [, $workers] = $this->s94PollUntil(
                fn(): array => $this->s94WorkerPids($serve['servePid']),
                static fn(array $pids): bool => $pids !== [],
                10.0,
                'a real analysis worker to be running mid-job (kill-time census)'
            );
            foreach ($workers as $pid) {
                $delivered = posix_kill($pid, 9);
                if ($delivered) {
                    continue;
                }
                // ESRCH is only an acceptable substitute for a delivered
                // kill when death is then OBSERVED within a bound: the
                // census raced the worker's own exit, and the state the
                // signal was meant to create must be proven anyway.
                $this->s94PollUntil(
                    fn(): bool => !$this->s94ProcLive($pid),
                    static fn(bool $dead): bool => $dead,
                    5.0,
                    "worker pid {$pid} to be provably dead after the kill raced its exit"
                );
            }
            $this->s94PollUntil(
                fn(): array => array_values(array_filter(
                    $workers,
                    fn(int $pid): bool => $this->s94ProcLive($pid)
                )),
                static fn(array $stillLive): bool => $stillLive === [],
                10.0,
                'every census worker to be dead after kill -9 (kill leg never established otherwise)'
            );

            // Every row that was live at the kill must settle as the
            // OBSERVED death — never success, never the absence-deadline
            // story. (S46: worker death never looks like completion.)
            foreach ($liveAtKill as $id) {
                [$observed, $row] = $this->s94PollUntil(
                    fn(): array => (array) json_decode($this->s94Http($serve['port'], 'GET', '/jobs/' . $id)['body'], true),
                    static fn(array $r): bool => ($r['status'] ?? '') === JobQueue::STATUS_COMPLETED
                        || ($r['status'] ?? '') === JobQueue::STATUS_FAILED,
                    20.0,
                    "job {$id} to settle after kill -9 of its worker"
                );
                $this->assertSame(JobQueue::STATUS_FAILED, $row['status'],
                    "a killed worker must never mint success (job {$id}, observed " . json_encode(array_column($observed, 'status')) . ')');
                $this->assertStringContainsString('ended without publishing a result', (string) $row['error']);
                $this->assertStringNotContainsString('did not produce a result', (string) $row['error']);
            }

            // The daemon outlives its worker: the serial loop keeps serving.
            $health = $this->s94Http($serve['port'], 'GET', '/healthz');
            $this->assertSame(200, $health['status']);
        } finally {
            $this->s94StopServe($serve);
        }
    }

    public function testLiveServeTrustGateAndBodyCapRefuseBeforeAnythingRuns(): void
    {
        $this->s94RequirePosixSupervision();
        $root = $this->repoRoot();
        $serve = $this->s94SpawnServe($root);
        try {
            // Host-spoof (the DNS-rebinding shape): 400 from the header
            // section — the route, and the queue, never see it.
            $spoof = $this->s94Http($serve['port'], 'POST', '/jobs', '{"paths":["tests/Fixtures/notify"]}', ['Host' => 'evil.example']);
            $this->assertSame(400, $spoof['status']);
            $this->assertStringContainsString('Host is not the address this server is bound to', $spoof['body']);

            // Cross-site Origin: 403 (the daemon never sends CORS, so no
            // wider semantics exist to honour).
            $origin = $this->s94Http($serve['port'], 'POST', '/jobs', '{}', ['Origin' => 'http://evil.example']);
            $this->assertSame(403, $origin['status']);
            $this->assertStringContainsString('Origin is not the origin this server answers', $origin['body']);

            // The text/plain simple-form shape: 415 before a body byte is
            // even consumed by the application.
            $plain = $this->s94Http($serve['port'], 'POST', '/analyze', '{}', ['Content-Type' => 'text/plain']);
            $this->assertSame(415, $plain['status']);
            $this->assertStringContainsString('Content-Type must be application/json', $plain['body']);

            // Oversize claim: the transport gate answers 413 off the header
            // alone — the client never sends the body it declared.
            $huge = $this->s94Http(
                $serve['port'],
                'POST',
                '/jobs',
                '',
                ['Content-Type' => 'application/json', 'Content-Length' => (string) (Application::MAX_BODY_BYTES + 1)]
            );
            $this->assertSame(413, $huge['status']);

            // None of the refused requests minted anything.
            $list = $this->s94Http($serve['port'], 'GET', '/api/jobs');
            $this->assertSame(200, $list['status']);
            $this->assertSame([], json_decode($list['body'], true));

            // And the daemon keeps serving its own authority.
            $health = $this->s94Http($serve['port'], 'GET', '/healthz');
            $this->assertSame(200, $health['status']);
        } finally {
            $this->s94StopServe($serve);
        }
    }

    public function testWorkerlessDeploymentRefusesJobsBeforeAnyValidation(): void
    {
        // once-S73, the Application-side owed pins (r1 of once-S73 named
        // this venue): the refusal door is the FIRST decision inside
        // POST /jobs — before path validation, before the refused-keys and
        // posture gates, before any row is minted — while the synchronous
        // routes and a real worker deployment are untouched.
        $queue = new JobQueue();
        $app = new Application($queue, $this->repoRoot(), null, null, null, false, 1, [], true);

        // A payload that would itself be a 400 (traversal) answers 503:
        // the door decides first.
        $traversal = $app->handle('POST', '/jobs', '{"paths":["../etc/passwd"]}', []);
        $this->assertSame(503, $traversal['status'], 'the refusal door precedes every validation leg');
        $this->assertSame(['error' => Application::asyncUnavailableError()], json_decode($traversal['body'], true));

        // No mint, no phantom row: the read routes keep their plain shape.
        $this->assertSame([], json_decode($app->handle('GET', '/api/jobs', '', [])['body'], true));
        $missing = $app->handle('GET', '/jobs/deadbeef', '', []);
        $this->assertSame(404, $missing['status']);
        $this->assertSame('job not found', json_decode($missing['body'], true)['error']);

        // The synchronous sibling is unaffected: same app, still answers.
        $health = $app->handle('GET', '/healthz', '', []);
        $this->assertSame(200, $health['status']);
        $this->assertSame('ok', $health['body']);

        // Control: the flag alone is not the sync-mode inline run — with a
        // worker socket named, the async dispatch path opens normally.
        $resultDir = sys_get_temp_dir() . '/phpdup-s94-door-' . uniqid('', true);
        mkdir($resultDir, 0700);
        try {
            $withWorker = new Application($queue2 = new JobQueue(), null, null, '/tmp/phpdup-s94-unused.sock', $resultDir, false, 1, [], true);
            $withWorker->workerAckProbe = $this->ackProbe('OK');
            $admitted = $withWorker->handle('POST', '/jobs', '{"paths":["src"]}', []);
            $this->assertSame(202, $admitted['status'], 'flag true with a worker present is a normal async admission');
            $this->assertCount(1, $queue2->list());
        } finally {
            @rmdir($resultDir);
        }
    }

    private function s94RequirePosixSupervision(): void
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('posix required to supervise the live serve battery');
        }
    }

    /**
     * Reserve a kernel-picked free loopback port (SweepTest's port-0 probe
     * convention), then boot the real daemon on it. A bind race (the tiny
     * window between the probe closing and the daemon binding) is retried
     * on a fresh port; every other failure is a loud, output-bearing fail.
     *
     * @return array{proc:resource,pipes:array<int,resource>,port:int,servePid:int,workerPid:int}
     */
    private function s94SpawnServe(string $serveRoot): array
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $errno = 0;
            $errstr = '';
            $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            $this->assertIsResource($probe, "cannot reserve an ephemeral loopback port: {$errstr}");
            $name = (string) stream_socket_get_name($probe, false);
            fclose($probe);
            $port = (int) substr($name, (int) strrpos($name, ':') + 1);
            $this->assertGreaterThan(0, $port, 'the port-0 probe must report a real port');

            $handle = $this->s94StartServeOn($serveRoot, $port);
            if ($handle !== null) {
                return $handle;
            }
        }
        $this->fail('live serve refused every ephemeral port it was handed');
    }

    /**
     * Boot `phpdup serve` and wait (poll-until-deadline) for the two facts
     * that make the battery's premise true: the daemon spawned its worker
     * (line carries the pid) and it bound its listener. Null only for the
     * one transient worth re-picking: the port went busy between probe and
     * bind. Any other early death fails loudly with the daemon's own output.
     *
     * @return array{proc:resource,pipes:array<int,resource>,port:int,servePid:int,workerPid:int}|null
     */
    private function s94StartServeOn(string $serveRoot, int $port): ?array
    {
        $cmd = [
            PHP_BINARY, $serveRoot . '/bin/phpdup', 'serve',
            '--host', '127.0.0.1',
            '--port', (string) $port,
            '--serve-root', $serveRoot,
        ];
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $serveRoot);
        $this->assertIsResource($proc, 'serve must spawn as a child process');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $servePid = proc_get_status($proc)['pid'];

        $out = '';
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk === false || $chunk === '') {
                // Drain complete for this wake (EAGAIN or EOF) — only now is
                // the liveness vote safe to take.
                if (!proc_get_status($proc)['running']) {
                    break;
                }
                usleep(25000);
                continue;
            }
            $out .= $chunk;
            if (str_contains($out, 'listening on')) {
                $this->assertSame(1, preg_match('/worker spawned \(pid (\d+)\)/', $out, $m),
                    'the battery needs the REAL async deployment; stdout was:' . PHP_EOL . $out);
                return [
                    'proc' => $proc, 'pipes' => $pipes, 'port' => $port,
                    'servePid' => $servePid, 'workerPid' => (int) $m[1],
                ];
            }
        }

        $err = (string) stream_get_contents($pipes[2]);
        $this->s94HardKill($proc, $pipes, $servePid);
        if (str_contains($out, 'bind failed')) {
            return null; // the ephemeral port got snatched mid-handoff: re-pick
        }
        $this->fail("serve died before it listened. stdout:" . PHP_EOL . $out . PHP_EOL . 'stderr:' . $err);
    }

    /**
     * @param array{proc:resource,pipes:array<int,resource>,port:int,servePid:int,workerPid:int} $serve
     */
    private function s94StopServe(array $serve): void
    {
        $servePid = $serve['servePid'];
        $workerPid = $serve['workerPid'];

        // The daemon's own graceful path first (shell-016): SIGTERM runs the
        // handler that reaps the worker, unlinks the socket and sweeps the
        // results dir. SIGKILL only ever after the grace deadline — and only
        // on the two pids this test can prove are its own children.
        posix_kill($servePid, 15);
        $deadline = microtime(true) + 8.0;
        while (microtime(true) < $deadline && $this->s94ProcLive($servePid)) {
            usleep(20000);
        }
        if ($this->s94ProcLive($servePid)) {
            posix_kill($servePid, 9);
        }
        // Close the pipes BEFORE proc_close: proc_close closes them itself,
        // and fclose() on a closed resource is a TypeError, not a warning.
        foreach ($serve['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        proc_terminate($serve['proc']);
        proc_close($serve['proc']);

        // The real worker (and any lingering wrapper) must not outlive the
        // test. Give the graceful path its moment — the worker's daemon-
        // death poll wakes on a 5 s accept timeout — then belt-kill by the
        // socket identity, never by anything broader.
        $deadline = microtime(true) + 9.0;
        while (microtime(true) < $deadline && $this->s94WorkerPids($servePid) !== []) {
            usleep(20000);
        }
        foreach ($this->s94WorkerPids($servePid) as $pid) {
            posix_kill($pid, 9);
        }
        if ($this->s94ProcLive($workerPid)) {
            posix_kill($workerPid, 9);
        }
        // once-S93: these final death checks were spot-censuses that raced
        // signal delivery under load (same kill-setup family as the arm in
        // the killed-worker test). Bounded observation decides the fact now;
        // window expiry still fails loud with the full observation trail.
        $this->s94PollUntil(
            fn(): array => $this->s94WorkerPids($servePid),
            static fn(array $pids): bool => $pids === [],
            5.0,
            'the spawned worker not to outlive the test'
        );
        $this->s94PollUntil(
            fn(): bool => !$this->s94ProcLive($servePid),
            static fn(bool $dead): bool => $dead,
            5.0,
            'the serve daemon not to outlive the test'
        );

        // Belt cleanup for a crash-skipped handler. Both paths are
        // pid-name-keyed to THIS daemon (never the shared startup sweep).
        @unlink(sys_get_temp_dir() . '/phpdup-worker-' . $servePid . '.sock');
        $resultsDir = sys_get_temp_dir() . '/phpdup-results-' . $servePid;
        if (is_dir($resultsDir)) {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($resultsDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $entry) {
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($resultsDir);
        }
    }

    /** @param array<int,resource> $pipes */
    private function s94HardKill($proc, array $pipes, int $servePid): void
    {
        posix_kill($servePid, 9);
        foreach ($this->s94WorkerPids($servePid) as $pid) {
            posix_kill($pid, 9);
        }
        proc_terminate($proc);
        proc_close($proc); // closes the pipes it created
    }

    /**
     * The REAL analysis worker pids of one daemon. Identity is the
     * daemon-unique worker socket path carried in the spawn argv (the same
     * path ServeCommand names in the worker line), filtered to live
     * processes and to the worker itself — the `sh` string-spawn wrapper is
     * skipped, since when the wrapper keeps its own pid the printed
     * 'worker spawned (pid N)' number is the wrapper's, not the analyst's.
     *
     * @return list<int>
     */
    private function s94WorkerPids(int $servePid): array
    {
        $needle = '/phpdup-worker-' . $servePid . '.sock';
        $workers = [];
        foreach (glob('/proc/[0-9]*') ?: [] as $dir) {
            $cmdline = @file_get_contents($dir . '/cmdline');
            if ($cmdline === false || !str_contains($cmdline, $needle)) {
                continue;
            }
            if (basename((string) explode("\0", $cmdline)[0]) === 'sh') {
                continue;
            }
            $pid = (int) basename($dir);
            if ($this->s94ProcLive($pid)) {
                $workers[] = $pid;
            }
        }
        return $workers;
    }

    /** Dead or reaped-in-waiting (zombie) is NOT live for supervision. */
    private function s94ProcLive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (!function_exists('posix_kill')) {
            return is_dir('/proc/' . $pid);
        }
        if (!posix_kill($pid, 0)) {
            return false;
        }
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($stat === false) {
            return false;
        }
        $fields = explode(' ', substr($stat, (int) strrpos($stat, ')') + 1));

        return ($fields[0] ?? 'Z') !== 'Z';
    }

    /**
     * Raw HTTP/1.1 exchange against the live daemon. The server answers one
     * request per connection and closes it, so reading to EOF is complete.
     *
     * @param array<string,string> $headers extra (or overriding) request headers
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function s94Http(int $port, string $method, string $path, string $body = '', array $headers = []): array
    {
        $lower = array_change_key_case($headers, CASE_LOWER);
        $lines = [sprintf('%s %s HTTP/1.1', $method, $path)];
        if (!isset($lower['host'])) {
            $lines[] = 'Host: 127.0.0.1:' . $port;
        }
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        if ($method === 'POST' && !isset($lower['content-length'])) {
            if (!isset($lower['content-type'])) {
                $lines[] = 'Content-Type: application/json';
            }
            $lines[] = 'Content-Length: ' . strlen($body);
        }
        $request = implode("\r\n", $lines) . "\r\nConnection: close\r\n\r\n";

        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 5.0);
        $this->assertIsResource($sock, "connect to the live daemon failed: {$errstr}");
        $this->assertGreaterThan(0, (int) @fwrite($sock, $request));
        if ($body !== '') {
            $this->assertGreaterThan(0, (int) @fwrite($sock, $body));
        }
        stream_set_timeout($sock, 15);

        $raw = '';
        while (!feof($sock)) {
            $chunk = fread($sock, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        fclose($sock);

        return $this->s94ParseHttpResponse($raw);
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    private function s94ParseHttpResponse(string $raw): array
    {
        $split = strpos($raw, "\r\n\r\n");
        $this->assertIsInt($split, 'the daemon must answer a framed HTTP response; got: ' . substr($raw, 0, 240));
        $lines = explode("\r\n", substr($raw, 0, $split));
        $this->assertSame(1, preg_match('#^HTTP/1\.1 (\d{3})#', $lines[0], $m), 'status line expected, got: ' . $lines[0]);

        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }

        return ['status' => (int) $m[1], 'headers' => $headers, 'body' => substr($raw, $split + 4)];
    }

    /**
     * Poll-until-deadline driver shared by every async wait in this era:
     * fixed sleeps never decide an async fact here.
     *
     * @param callable():T $fetch
     * @param callable(T):bool $done
     * @return array{0:list<T>,1:T}
     *
     * @template T
     */
    private function s94PollUntil(callable $fetch, callable $done, float $deadlineSeconds, string $what): array
    {
        $observed = [];
        $deadline = microtime(true) + $deadlineSeconds;
        do {
            $value = $fetch();
            $observed[] = $value;
            if ($done($value)) {
                return [$observed, $value];
            }
            usleep(50000);
        } while (microtime(true) < $deadline);

        $this->fail("live serve: waited {$deadlineSeconds}s for {$what}; observed: " . json_encode($observed));
    }

    // ------------------------------------------------------------------
    // once-S41 (php-dup-finder-once-045): symlink-out-of-root refusal —
    // the serve-chain closure. Two tiers, both pinned here: a SUBMITTED
    // path that resolves through a symlink out of the root answers the
    // admission 400 before anything runs (both routes), while a symlink
    // NESTED inside an admitted tree is contained by the walk (once-S20)
    // — skipped, never served — and the skip count surfaces on the
    // once-S37 scan_notes carrier that every serve transport now threads
    // through the one state→Report seam, Application::buildServeReport().
    // The summary.scan_notes JSON key itself is the owner-gated schema-
    // 1.8 emit; the 1.7 result body stays byte-frozen (belt: the
    // JsonReporterTest shape tripwire from once-S37 fix1).
    // ------------------------------------------------------------------

    /**
     * Build a scratch serve tree: two in-root PHP files, an in-root
     * directory alias (legit symlink), and two escaping links (a
     * directory link and a file link) pointing at an OUTSIDE tree that
     * carries a unique sentinel function.
     *
     * @return array{root:string,outside:string}
     */
    private function s41EscapeTree(): array
    {
        $base = sys_get_temp_dir() . '/phpdup-s41-' . uniqid();
        $root = $base . '/root';
        $outside = $base . '/outside';
        mkdir($root . '/keep', 0700, true);
        mkdir($outside, 0700, true);
        file_put_contents($root . '/a.php', "<?php\nfunction s41_alpha(\$x) { return \$x * 3 + 1; }\n");
        file_put_contents($root . '/keep/k.php', "<?php\nfunction s41_beta(\$y) { return \$y * 3 + 1; }\n");
        file_put_contents($outside . '/Secret.php', "<?php\nfunction secret_marker_zx9(\$z) { return \$z * 3 + 1; }\n");
        symlink($outside, $root . '/evil');
        symlink($outside . '/Secret.php', $root . '/sneaky.php');
        symlink($root . '/keep', $root . '/alias-inside');

        return ['root' => $root, 'outside' => $outside];
    }

    /** Remove a scratch tree without ever following its links. */
    private function s41Purge(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                @unlink($path);
                continue;
            }
            $this->s41Purge($path);
            @rmdir($path);
        }
        @rmdir($dir);
    }

    public function testS41SubmittedSymlinkedDirPathRefusedBeforeScan(): void
    {
        // Tier one, directory link: naming the escaping link AS the path
        // resolves (realpath follows every component) outside the root —
        // the admission 400, before any analysis runs.
        ['root' => $root, 'outside' => $outside] = $this->s41EscapeTree();
        $app = new Application(new JobQueue(), $root, null);
        $cwd = getcwd();
        chdir($root);

        try {
            $response = $app->handle('POST', '/analyze', '{"paths":["evil"]}', []);

            $this->assertSame(400, $response['status']);
            $this->assertStringContainsString('path is outside serve-root: evil', $response['body']);
        } finally {
            chdir($cwd);
            $this->s41Purge($root);
            $this->s41Purge($outside);
            @rmdir(dirname($root));
        }
    }

    public function testS41SubmittedSymlinkedFilePathRefusedBeforeScan(): void
    {
        // Tier one, file link: the same refusal for a symlinked FILE
        // naming outside content (the NET-02 shape read /etc/passwd
        // through exactly this door — the file arm must refuse too).
        ['root' => $root, 'outside' => $outside] = $this->s41EscapeTree();
        $app = new Application(new JobQueue(), $root, null);
        $cwd = getcwd();
        chdir($root);

        try {
            $response = $app->handle('POST', '/analyze', '{"paths":["sneaky.php"]}', []);

            $this->assertSame(400, $response['status']);
            $this->assertStringContainsString('path is outside serve-root: sneaky.php', $response['body']);
        } finally {
            chdir($cwd);
            $this->s41Purge($root);
            $this->s41Purge($outside);
            @rmdir(dirname($root));
        }
    }

    public function testS41JobsSymlinkEscapeRefusedBeforeMintingRow(): void
    {
        // Tier one on the async door: the 400 is an admission refusal —
        // no queue row exists afterwards, so nothing can ever dispatch,
        // run, or hold a slot for the escaped scan.
        ['root' => $root, 'outside' => $outside] = $this->s41EscapeTree();
        $queue = new JobQueue();
        $app = new Application($queue, $root, null);
        $cwd = getcwd();
        chdir($root);

        try {
            $response = $app->handle('POST', '/jobs', '{"paths":["evil"]}', []);

            $this->assertSame(400, $response['status']);
            $this->assertStringContainsString('path is outside serve-root: evil', $response['body']);
            $this->assertSame([], $queue->list(), 'a refused escape must mint no job row');
        } finally {
            chdir($cwd);
            $this->s41Purge($root);
            $this->s41Purge($outside);
            @rmdir(dirname($root));
        }
    }

    public function testS41NestedSymlinkEscapeScannedNeverServed(): void
    {
        // Tier two, wire shape: the submitted path itself is in-root
        // (admission passes), and only the WALK can see the escapes it
        // contains. The result is the in-root census — the outside
        // sentinel and the outside tree's paths are not one byte of the
        // response, and the legit in-root alias still counts.
        ['root' => $root, 'outside' => $outside] = $this->s41EscapeTree();
        $app = new Application(new JobQueue(), $root, null);
        $cwd = getcwd();
        chdir($root);

        try {
            $response = $app->handle('POST', '/analyze', '{"paths":["."]}', []);

            $this->assertSame(200, $response['status']);
            $decoded = json_decode($response['body'], true);
            $this->assertIsArray($decoded);
            $this->assertSame(2, $decoded['summary']['files'], 'a.php + keep/k.php only');
            $this->assertStringNotContainsString('secret_marker_zx9', $response['body']);
            $this->assertStringNotContainsString($outside, $response['body']);
        } finally {
            chdir($cwd);
            $this->s41Purge($root);
            $this->s41Purge($outside);
            @rmdir(dirname($root));
        }
    }

    public function testS41InRootSymlinkStillServedAtLiteralPath(): void
    {
        // Straddle pin: confinement must not regress legitimate symlinks.
        // An in-root alias is scanned at its literal path — the refusal
        // vocabulary is for escapes only.
        ['root' => $root, 'outside' => $outside] = $this->s41EscapeTree();
        $app = new Application(new JobQueue(), $root, null);
        $cwd = getcwd();
        chdir($root);

        try {
            $response = $app->handle('POST', '/analyze', '{"paths":["alias-inside"]}', []);

            $this->assertSame(200, $response['status']);
            $decoded = json_decode($response['body'], true);
            $this->assertIsArray($decoded);
            $this->assertSame(1, $decoded['summary']['files']);
            $this->assertStringContainsString('alias-inside', $response['body']);
        } finally {
            chdir($cwd);
            $this->s41Purge($root);
            $this->s41Purge($outside);
            @rmdir(dirname($root));
        }
    }

    public function testS41ServeReportThreadsScanNotesCount(): void
    {
        // The seam itself: the run's skip notes ride the once-S37
        // scan_notes carrier, and an idle scan carries the honest empty
        // list (never a fabricated count, never null laundering).
        $config = \Phpdup\Cli\Config::defaults(['.']);
        $state = new \Phpdup\Pipeline\PipelineState($config);
        $this->assertSame([], Application::buildServeReport($state, $config)->scanNotes);

        $state->scanNotes = [
            'skipped symlinked path ./evil (target escapes the scan root)',
            'skipped symlinked path ./sneaky.php (target escapes the scan root)',
            'skipped unreadable path ./locked.php (directory is not readable)',
        ];
        $report = Application::buildServeReport($state, $config);

        $this->assertSame($state->scanNotes, $report->scanNotes);
        $this->assertCount(3, $report->scanNotes);
    }

    public function testS41LiveScanNotesReachServeReportCarrier(): void
    {
        // The count that the plan's acceptance names: a REAL serve-shaped
        // scan (the once-S20 walk containment mints the notes through
        // ScanningStage's channel drain) ends with exactly those notes —
        // two escaping links, each a `target escapes the scan root`
        // skip-note — on the carrier the server report carries. The
        // legit in-root alias earns no note.
        ['root' => $root, 'outside' => $outside] = $this->s41EscapeTree();
        $cwd = getcwd();
        chdir($root);

        try {
            $config = \Phpdup\Cli\Config::defaults(['.']);
            $state = new \Phpdup\Pipeline\PipelineState($config);
            (new \Phpdup\Pipeline\Stages\ScanningStage())->run($state, new \Symfony\Component\Console\Output\NullOutput());

            $notes = Application::buildServeReport($state, $config)->scanNotes;

            $this->assertCount(2, $notes);
            $this->assertStringContainsString('skipped symlinked path', $notes[0]);
            $this->assertStringContainsString('evil', $notes[0]);
            $this->assertStringContainsString('target escapes the scan root', $notes[0]);
            $this->assertStringContainsString('sneaky.php', $notes[1]);
            $this->assertStringNotContainsString('alias-inside', implode('|', $notes));
        } finally {
            chdir($cwd);
            $this->s41Purge($root);
            $this->s41Purge($outside);
            @rmdir(dirname($root));
        }
    }

    public function testS41WorkerJobConfinesNestedEscape(): void
    {
        // Tier two on the async transport: a hand-fed RUN frame naming
        // the admitted root publishes a result the escapes never entered
        // — and the worker stays alive to say so. The async leg shares
        // the sync leg's one Report seam, so the carrier threading is
        // byte-invisible at the frozen 1.7 shape by construction: the
        // wire check IS the closure check.
        ['root' => $root, 'outside' => $outside] = $this->s41EscapeTree();
        $cwd = getcwd();
        chdir($root);

        try {
            [$client, $controller] = $this->makeWorkerPair();
            $handleClient = new \ReflectionMethod(AnalysisWorker::class, 'handleClient');
            fwrite($controller, "RUN 0123456789abcdef {\"paths\":[\".\"]}\n");
            $handleClient->invoke(new AnalysisWorker(
                '/tmp/phpdup-worker-defence-unused.sock',
                $this->workerResultDir,
                $root
            ), $client);
            $ack = rtrim((string) fgets($controller), "\r\n");

            $this->assertSame('OK 0123456789abcdef', $ack);
            $file = $this->workerResultDir . '/0123456789abcdef.json';
            $this->assertFileExists($file);
            $body = (string) file_get_contents($file);
            $decoded = json_decode($body, true);
            $this->assertIsArray($decoded);
            $this->assertSame(2, $decoded['summary']['files'], 'a.php + keep/k.php only');
            $this->assertStringNotContainsString('secret_marker_zx9', $body);
            $this->assertStringNotContainsString($outside, $body);
        } finally {
            chdir($cwd);
            $this->s41Purge($root);
            $this->s41Purge($outside);
            @rmdir(dirname($root));
        }
    }
}
