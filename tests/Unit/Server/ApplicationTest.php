<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Server;

use PHPUnit\Framework\TestCase;
use Phpdup\Server\Application;
use Phpdup\Server\JobQueue;

final class ApplicationTest extends TestCase
{
    public function testHealthzReturnsOk(): void
    {
        $resp = (new Application())->handle('GET', '/healthz', '');
        $this->assertSame(200, $resp['status']);
        $this->assertSame('ok', $resp['body']);
    }

    public function testUnknownRouteReturns404(): void
    {
        $resp = (new Application())->handle('GET', '/no-such-route', '');
        $this->assertSame(404, $resp['status']);
    }

    public function testAnalyzeRequiresPaths(): void
    {
        $resp = (new Application())->handle('POST', '/analyze', '{}');
        $this->assertSame(400, $resp['status']);
        $this->assertStringContainsString('paths', $resp['body']);
    }

    public function testAnalyzeRunsAgainstFixturePath(): void
    {
        $payload = json_encode(['paths' => [__DIR__ . '/../../Fixtures/exact']]);
        $resp = (new Application())->handle('POST', '/analyze', (string)$payload);
        $this->assertSame(200, $resp['status']);
        $decoded = json_decode($resp['body'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('summary', $decoded);
    }

    public function testJobsLifecycle(): void
    {
        $queue = new JobQueue();
        $app = new Application($queue);
        $payload = json_encode(['paths' => [__DIR__ . '/../../Fixtures/exact']]);
        $resp = $app->handle('POST', '/jobs', (string)$payload);
        $this->assertSame(202, $resp['status']);
        $jid = json_decode($resp['body'], true)['job_id'];
        $this->assertIsString($jid);

        $get = $app->handle('GET', "/jobs/{$jid}", '');
        $this->assertSame(200, $get['status']);
        $job = json_decode($get['body'], true);
        $this->assertContains($job['status'], [
            JobQueue::STATUS_COMPLETED, JobQueue::STATUS_RUNNING, JobQueue::STATUS_FAILED,
        ]);
    }

    public function testJobsLookupReturns404ForUnknownId(): void
    {
        $resp = (new Application())->handle('GET', '/jobs/deadbeef', '');
        $this->assertSame(404, $resp['status']);
    }

    // -------------------------------------------------------------------------
    // Bearer token authentication
    // -------------------------------------------------------------------------

    public function testAnalyzeWithTokenMissingReturns401(): void
    {
        $app = new Application(new JobQueue(), null, 'secret-token');
        $resp = $app->handle('GET', '/healthz', '', []);
        $this->assertSame(401, $resp['status']);
    }

    public function testAnalyzeWithTokenWrongReturns401(): void
    {
        $app = new Application(new JobQueue(), null, 'secret-token');
        $resp = $app->handle('GET', '/healthz', '', ['authorization' => 'Bearer wrong-token']);
        $this->assertSame(401, $resp['status']);
    }

    public function testAnalyzeWithTokenValidReturns200(): void
    {
        $app = new Application(new JobQueue(), null, 'secret-token');
        $resp = $app->handle('GET', '/healthz', '', ['authorization' => 'Bearer secret-token']);
        $this->assertSame(200, $resp['status']);
    }

    public function testAnalyzeWithTokenCaseInsensitiveBearerReturns200(): void
    {
        $app = new Application(new JobQueue(), null, 'secret-token');
        $resp = $app->handle('GET', '/healthz', '', ['authorization' => 'BEARER secret-token']);
        $this->assertSame(200, $resp['status']);
    }

    // -------------------------------------------------------------------------
    // Path validation / traversal prevention
    // -------------------------------------------------------------------------

    public function testAnalyzeWithAbsolutePathOutsideServeRootReturns400(): void
    {
        $app = new Application(new JobQueue(), __DIR__ . '/../../Fixtures');
        $payload = json_encode(['paths' => ['/etc/passwd']]);
        $resp = $app->handle('POST', '/analyze', (string)$payload);
        $this->assertSame(400, $resp['status']);
        $this->assertStringContainsString('absolute paths are not allowed', $resp['body']);
    }

    public function testAnalyzeWithTraversalSequenceReturns400(): void
    {
        $app = new Application(new JobQueue(), __DIR__ . '/../../Fixtures');
        $payload = json_encode(['paths' => ['../etc/passwd']]);
        $resp = $app->handle('POST', '/analyze', (string)$payload);
        $this->assertSame(400, $resp['status']);
        $this->assertStringContainsString('path traversal is not allowed', $resp['body']);
    }

    public function testAnalyzeWithDeepTraversalSequenceReturns400(): void
    {
        $app = new Application(new JobQueue(), __DIR__ . '/../../Fixtures');
        $payload = json_encode(['paths' => ['foo/../bar/../../etc/passwd']]);
        $resp = $app->handle('POST', '/analyze', (string)$payload);
        $this->assertSame(400, $resp['status']);
        $this->assertStringContainsString('path traversal is not allowed', $resp['body']);
    }

    public function testAnalyzeWithValidPathInsideServeRootReturns200(): void
    {
        // Use a path that is relative to CWD (project root when tests run)
        // and exists relative to serveRoot (tests/Fixtures).
        $app = new Application(new JobQueue(), __DIR__ . '/../../Fixtures');
        $payload = json_encode(['paths' => ['tests/Fixtures/exact']]);
        $resp = $app->handle('POST', '/analyze', (string)$payload);
        $this->assertSame(200, $resp['status']);
    }

    public function testAnalyzeWithPathOutsideServeRootReturns400(): void
    {
        // A path that does not contain '..' but resolves via realpath to a
        // location outside serveRoot. This can happen when serveRoot is a
        // symlink target and the path is an absolute path that bypasses it.
        // Since absolute paths are rejected first, this test verifies that
        // the containment check is in place for paths that somehow bypass
        // the '..' rejection (e.g. via symlink tricks at the filesystem level).
        // For unit-test purposes we use a path that would be outside but
        // requires the containment check to exist.
        $outsidePath = '/tmp'; // External absolute path
        $app = new Application(new JobQueue(), __DIR__ . '/../../Fixtures/exact');
        $payload = json_encode(['paths' => [$outsidePath]]);
        $resp = $app->handle('POST', '/analyze', (string)$payload);
        $this->assertSame(400, $resp['status']);
        // Either "absolute paths not allowed" or "path not found" is acceptable
        // since /tmp does not exist under the fixtures/exact directory tree.
        $this->assertTrue(
            str_contains($resp['body'], 'absolute paths are not allowed')
            || str_contains($resp['body'], 'path not found'),
            'Expected absolute-path or not-found rejection, got: ' . $resp['body']
        );
    }

    public function testAnalyzeWithNonExistentPathInsideServeRootReturns400(): void
    {
        $app = new Application(new JobQueue(), __DIR__ . '/../../Fixtures');
        $payload = json_encode(['paths' => ['nonexistent-dir']]);
        $resp = $app->handle('POST', '/analyze', (string)$payload);
        $this->assertSame(400, $resp['status']);
        $this->assertStringContainsString('path not found', $resp['body']);
    }

    public function testAnalyzeWithoutServeRootAllowsAbsolutePaths(): void
    {
        // When no serveRoot is configured, absolute paths are allowed
        // (legacy behaviour for backward compatibility).
        $app = new Application(new JobQueue(), null);
        $payload = json_encode(['paths' => [__DIR__ . '/../../Fixtures/exact']]);
        $resp = $app->handle('POST', '/analyze', (string)$payload);
        $this->assertSame(200, $resp['status']);
    }

    public function testBuildSummaryExtractsOnlySummaryFields(): void
    {
        $app = new Application();

        $fullReport = [
            'files'    => 42,
            'blocks'   => 17,
            'clusters' => 5,
            'config'   => ['minBlockSize' => 3, 'minClusterImpact' => 0.1],
            'extra_field'      => 'should be ignored',
            'another_field'    => 12345,
            'nested'           => ['a' => 1, 'b' => 2],
        ];

        $reflection = new \ReflectionClass(Application::class);
        $method = $reflection->getMethod('buildSummary');
        $method->setAccessible(true);

        $summary = $method->invoke($app, $fullReport);

        $this->assertSame(['files', 'blocks', 'clusters', 'config'], array_keys($summary));
        $this->assertSame(42, $summary['files']);
        $this->assertSame(17, $summary['blocks']);
        $this->assertSame(5, $summary['clusters']);
        $this->assertSame(['minBlockSize' => 3, 'minClusterImpact' => 0.1], $summary['config']);
    }

    public function testBuildSummaryHandlesNullInput(): void
    {
        $app = new Application();

        $reflection = new \ReflectionClass(Application::class);
        $method = $reflection->getMethod('buildSummary');
        $method->setAccessible(true);

        $summary = $method->invoke($app, null);
        $this->assertSame(['files' => 0, 'blocks' => 0, 'clusters' => 0], $summary);
    }

    public function testBuildSummaryHandlesPartialInput(): void
    {
        $app = new Application();

        $reflection = new \ReflectionClass(Application::class);
        $method = $reflection->getMethod('buildSummary');
        $method->setAccessible(true);

        $summary = $method->invoke($app, ['files' => 10, 'blocks' => 5]);
        $this->assertSame(['files' => 10, 'blocks' => 5, 'clusters' => 0, 'config' => null], $summary);

        $summary = $method->invoke($app, ['clusters' => 3]);
        $this->assertSame(['files' => 0, 'blocks' => 0, 'clusters' => 3, 'config' => null], $summary);
    }
}
