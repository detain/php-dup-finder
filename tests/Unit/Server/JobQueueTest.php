<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Server;

use PHPUnit\Framework\TestCase;
use Phpdup\Server\JobQueue;

final class JobQueueTest extends TestCase
{
    public function testEnqueueReturnsUniqueIds(): void
    {
        $queue = new JobQueue();
        $id1 = $queue->enqueue(['paths' => ['a']]);
        $id2 = $queue->enqueue(['paths' => ['b']]);
        $this->assertNotSame($id1, $id2);
    }

    public function testGetReturnsNullForUnknownId(): void
    {
        $queue = new JobQueue();
        $this->assertNull($queue->get('nonexistent'));
    }

    public function testMarkRunningTransitionsPendingToRunning(): void
    {
        $queue = new JobQueue();
        $id = $queue->enqueue(['paths' => ['a']]);
        $queue->markRunning($id);

        $job = $queue->get($id);
        $this->assertSame(JobQueue::STATUS_RUNNING, $job['status']);
    }

    public function testMarkRunningIgnoresUnknownId(): void
    {
        $queue = new JobQueue();
        $queue->markRunning('nonexistent');
        $this->assertNull($queue->get('nonexistent'));
    }

    public function testMarkCompletedStoresSummary(): void
    {
        $queue = new JobQueue();
        $id = $queue->enqueue(['paths' => ['a']]);
        $summary = ['files' => 10, 'blocks' => 5, 'clusters' => 2, 'config' => ['minBlockSize' => 3]];
        $queue->markCompleted($id, $summary);

        $job = $queue->get($id);
        $this->assertSame(JobQueue::STATUS_COMPLETED, $job['status']);
        $this->assertSame($summary, $job['result']);
    }

    public function testMarkCompletedIgnoresUnknownId(): void
    {
        $queue = new JobQueue();
        $queue->markCompleted('nonexistent', ['files' => 0, 'blocks' => 0, 'clusters' => 0]);
        $this->assertNull($queue->get('nonexistent'));
    }

    public function testMarkFailedStoresError(): void
    {
        $queue = new JobQueue();
        $id = $queue->enqueue(['paths' => ['a']]);
        $queue->markFailed($id, 'something went wrong');

        $job = $queue->get($id);
        $this->assertSame(JobQueue::STATUS_FAILED, $job['status']);
        $this->assertSame('something went wrong', $job['error']);
    }

    public function testMarkFailedIgnoresUnknownId(): void
    {
        $queue = new JobQueue();
        $queue->markFailed('nonexistent', 'error');
        $this->assertNull($queue->get('nonexistent'));
    }

    private function getJobs(JobQueue $queue): array
    {
        $jobs = [];
        $reflection = new \ReflectionClass($queue);
        $prop = $reflection->getProperty('jobs');
        $prop->setAccessible(true);
        /** @var array<string, array{status:string, payload:array<string,mixed>, result:?array<string,mixed>, error:?string, created_at:float, completed_at:?float}> $raw */
        $raw = $prop->getValue($queue);
        foreach ($raw as $id => $job) {
            $jobs[$id] = [
                'status'  => $job['status'],
                'payload' => $job['payload'],
                'result'  => $job['result'],
                'error'   => $job['error'],
            ];
        }
        return $jobs;
    }

    public function testQueueIsBoundedAtMaxJobs(): void
    {
        $queue = new JobQueue();

        for ($i = 0; $i < JobQueue::MAX_JOBS; $i++) {
            $id = $queue->enqueue(['index' => $i]);
            $queue->markCompleted($id, ['files' => $i, 'blocks' => 0, 'clusters' => 0]);
        }

        $this->assertCount(JobQueue::MAX_JOBS, $this->getJobs($queue));

        $firstId = $this->getFirstJobId($queue);

        $newId = $queue->enqueue(['index' => 999]);
        $queue->markCompleted($newId, ['files' => 999, 'blocks' => 0, 'clusters' => 0]);

        $this->assertCount(JobQueue::MAX_JOBS, $this->getJobs($queue));
        $this->assertNull($queue->get($firstId));
        $this->assertNotNull($queue->get($newId));
    }

    public function testPendingAndRunningJobsAreNotEvicted(): void
    {
        $queue = new JobQueue();

        for ($i = 0; $i < JobQueue::MAX_JOBS; $i++) {
            $id = $queue->enqueue(['index' => $i]);
            if ($i % 2 === 0) {
                $queue->markRunning($id);
            }
        }

        $this->assertCount(JobQueue::MAX_JOBS, $this->getJobs($queue));

        $overflowId = $queue->enqueue(['index' => 999]);
        $queue->markCompleted($overflowId, ['files' => 999, 'blocks' => 0, 'clusters' => 0]);

        $this->assertCount(JobQueue::MAX_JOBS + 1, $this->getJobs($queue));

        $pendingOrRunning = 0;
        foreach ($this->getJobs($queue) as $job) {
            if ($job['status'] === JobQueue::STATUS_PENDING || $job['status'] === JobQueue::STATUS_RUNNING) {
                $pendingOrRunning++;
            }
        }
        $this->assertSame(JobQueue::MAX_JOBS, $pendingOrRunning);
    }

    public function testCleanupRemovesAllTerminalJobs(): void
    {
        $queue = new JobQueue();

        $completedIds = [];
        for ($i = 0; $i < 5; $i++) {
            $id = $queue->enqueue(['index' => $i]);
            $queue->markCompleted($id, ['files' => $i, 'blocks' => 0, 'clusters' => 0]);
            $completedIds[] = $id;
        }

        $pendingId = $queue->enqueue(['index' => 99]);
        $queue->markRunning($pendingId);

        $queue->cleanup();

        foreach ($completedIds as $id) {
            $this->assertNull($queue->get($id));
        }
        $this->assertNotNull($queue->get($pendingId));
    }

    public function testEvictStalePurgesExpiredCompletedJobs(): void
    {
        $queue = new JobQueue();
        $id = $queue->enqueue(['paths' => ['a']]);
        $queue->markCompleted($id, ['files' => 1, 'blocks' => 0, 'clusters' => 0]);

        $this->assertNotNull($queue->get($id));

        $reflection = new \ReflectionClass($queue);
        $prop = $reflection->getProperty('jobs');
        $prop->setAccessible(true);
        /** @var array<string, array{status:string, payload:array<string,mixed>, result:?array<string,mixed>, error:?string, created_at:float, completed_at:?float}> $jobs */
        $jobs = $prop->getValue($queue);
        $jobs[$id]['completed_at'] = $jobs[$id]['completed_at'] - JobQueue::JOB_TTL_SECONDS - 1;
        $prop->setValue($queue, $jobs);

        $queue->evictStale();

        $this->assertNull($queue->get($id));
    }

    public function testEvictStaleLeavesFreshCompletedJobs(): void
    {
        $queue = new JobQueue();
        $id = $queue->enqueue(['paths' => ['a']]);
        $queue->markCompleted($id, ['files' => 1, 'blocks' => 0, 'clusters' => 0]);

        $this->assertNotNull($queue->get($id));

        $queue->evictStale();

        $this->assertNotNull($queue->get($id));
    }

    public function testEvictStaleLeavesPendingAndRunningJobs(): void
    {
        $queue = new JobQueue();

        $pendingId = $queue->enqueue(['paths' => ['a']]);
        $runningId = $queue->enqueue(['paths' => ['b']]);
        $queue->markRunning($runningId);

        $reflection = new \ReflectionClass($queue);
        $prop = $reflection->getProperty('jobs');
        $prop->setAccessible(true);
        /** @var array<string, array{status:string, payload:array<string,mixed>, result:?array<string,mixed>, error:?string, created_at:float, completed_at:?float}> $jobs */
        $jobs = $prop->getValue($queue);
        $jobs[$pendingId]['created_at'] = $jobs[$pendingId]['created_at'] - JobQueue::JOB_TTL_SECONDS - 10;
        $jobs[$runningId]['created_at'] = $jobs[$runningId]['created_at'] - JobQueue::JOB_TTL_SECONDS - 10;
        $prop->setValue($queue, $jobs);

        $queue->evictStale();

        $this->assertNotNull($queue->get($pendingId));
        $this->assertNotNull($queue->get($runningId));
    }

    private function getFirstJobId(JobQueue $queue): ?string
    {
        $reflection = new \ReflectionClass($queue);
        $prop = $reflection->getProperty('jobs');
        $prop->setAccessible(true);
        /** @var array<string, array{status:string, payload:array<string,mixed>, result:?array<string,mixed>, error:?string, created_at:float, completed_at:?float}> $jobs */
        $jobs = $prop->getValue($queue);
        $keys = array_keys($jobs);
        return $keys[0] ?? null;
    }
}
