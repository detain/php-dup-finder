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
}
