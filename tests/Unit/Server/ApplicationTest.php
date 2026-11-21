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
}
