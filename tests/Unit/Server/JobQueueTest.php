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
        /** @var array<string, array{status:string, payload:array{digest:string, bytes:int}, result:?array<string,mixed>, error:?string, created_at:float, completed_at:?float}> $raw */
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

    /**
     * once-S02 (php-dup-finder-once-002) deliberately re-cuts the shell-S06
     * "pending/running are never auto-evicted" posture: the plan row books
     * this ("MAX_JOBS eviction policy touches shell-S06 semantics") because
     * that posture was the cap bypass — a flood grew the queue unbounded.
     * The eviction CLASS PREFERENCE survives: live work is only displaced
     * when nothing terminal exists, pending before running, oldest first,
     * and every displacement is recorded with a visible note.
     */
    public function testCapEvictsOldestPendingBeforeRunningWithVisibleNote(): void
    {
        $queue = new JobQueue();

        $ids = [];
        for ($i = 0; $i < JobQueue::MAX_JOBS; $i++) {
            $ids[$i] = $queue->enqueue(['index' => $i]);
            if ($i % 2 === 0) {
                $queue->markRunning($ids[$i]);
            }
        }

        $this->assertCount(JobQueue::MAX_JOBS, $this->getJobs($queue));

        $overflowId = $queue->enqueue(['index' => 999]);

        // Hard ceiling: the queue never grew past MAX_JOBS.
        $this->assertCount(JobQueue::MAX_JOBS, $this->getJobs($queue));

        // The oldest PENDING (index 1 — evens went running) was displaced;
        // every RUNNING row survived the pressure.
        $this->assertNull($queue->get($ids[1]));
        $this->assertSame(JobQueue::STATUS_RUNNING, $queue->get($ids[0])['status']);
        $this->assertNotNull($queue->get($ids[3]));
        $this->assertNotNull($queue->get($overflowId));

        // Visible note: status at eviction, capacity reason, and a lookup
        // that explains the id instead of letting it vanish silently.
        $this->assertCount(1, $queue->lastEvictions());
        $note = $queue->lastEvictions()[0];
        $this->assertSame($ids[1], $note['job_id']);
        $this->assertSame(JobQueue::STATUS_PENDING, $note['status']);
        $this->assertStringContainsString('at capacity', $note['reason']);
        $this->assertSame($note['reason'], $queue->evictionNote($ids[1]));
    }

    public function testCapDisplacesOldestRunningWhenEveryRowIsRunning(): void
    {
        $queue = new JobQueue();
        $ids = [];
        for ($i = 0; $i < JobQueue::MAX_JOBS; $i++) {
            $ids[$i] = $queue->enqueue(['index' => $i]);
            $queue->markRunning($ids[$i]);
        }

        $queue->enqueue(['index' => 999]);

        $this->assertCount(JobQueue::MAX_JOBS, $this->getJobs($queue));
        $this->assertNull($queue->get($ids[0]), 'the oldest running row is the last-resort victim');
        $this->assertNotNull($queue->get($ids[1]));

        $note = $queue->lastEvictions()[0];
        $this->assertSame($ids[0], $note['job_id']);
        $this->assertSame(JobQueue::STATUS_RUNNING, $note['status']);
        $this->assertStringContainsString('capacity', (string) $queue->evictionNote($ids[0]));
    }

    public function testCapPrefersTerminalEvictionAndNotesItToo(): void
    {
        $queue = new JobQueue();
        $pendingIds = [];
        for ($i = 0; $i < JobQueue::MAX_JOBS - 1; $i++) {
            $pendingIds[$i] = $queue->enqueue(['index' => $i]);
        }
        $terminalId = $queue->enqueue(['index' => 'terminal']);
        $queue->markCompleted($terminalId, ['files' => 1, 'blocks' => 0, 'clusters' => 0]);

        $queue->enqueue(['index' => 999]);

        // The terminal row paid for the new one; pending work untouched.
        $this->assertCount(JobQueue::MAX_JOBS, $this->getJobs($queue));
        $this->assertNull($queue->get($terminalId));
        foreach ($pendingIds as $id) {
            $this->assertNotNull($queue->get($id));
        }
        $this->assertSame([$terminalId], array_column($queue->lastEvictions(), 'job_id'));
        $this->assertSame(JobQueue::STATUS_COMPLETED, $queue->lastEvictions()[0]['status']);
    }

    public function testEvictionLedgerIsBoundedAndTtlPurgesAreNotNotes(): void
    {
        $queue = new JobQueue();

        // All-pending queue at cap: each further enqueue displaces exactly
        // the oldest pending row (terminal class always empty).
        for ($i = 0; $i < JobQueue::MAX_JOBS; $i++) {
            $queue->enqueue(['index' => $i]);
        }
        $extra = JobQueue::MAX_EVICTION_NOTES + 5;
        $lastVictim = '';
        for ($i = 0; $i < $extra; $i++) {
            $queue->enqueue(['index' => 'x' . $i]);
            $lastVictim = $queue->lastEvictions()[0]['job_id'];
        }

        $this->assertCount(JobQueue::MAX_JOBS, $this->getJobs($queue));
        $this->assertLessThanOrEqual(JobQueue::MAX_EVICTION_NOTES, count($queue->evictions()));
        $this->assertNotNull($queue->evictionNote($lastVictim), 'the newest note is retained');

        // TTL expiry is not a capacity displacement: no note appears.
        $purgeId = $queue->enqueue(['index' => 'ttl']);
        $queue->markCompleted($purgeId, ['files' => 0, 'blocks' => 0, 'clusters' => 0]);
        $reflection = new \ReflectionClass($queue);
        $prop = $reflection->getProperty('jobs');
        /** @var array<string, array<string,mixed>> $jobs */
        $jobs = $prop->getValue($queue);
        $jobs[$purgeId]['completed_at'] = ($jobs[$purgeId]['completed_at'] ?? 0) - JobQueue::JOB_TTL_SECONDS - 1;
        $prop->setValue($queue, $jobs);
        $queue->evictStale();

        $this->assertNull($queue->get($purgeId));
        $this->assertNull($queue->evictionNote($purgeId), 'a TTL purge keeps the plain not-found story');
    }

    public function testEvictionNoteAndLastEvictionsStayEmptyWithoutPressure(): void
    {
        $queue = new JobQueue();
        $queue->enqueue(['paths' => ['a']]);

        $this->assertSame([], $queue->lastEvictions());
        $this->assertSame([], $queue->evictions());
        $this->assertNull($queue->evictionNote('does-not-exist'));
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
        /** @var array<string, array{status:string, payload:array{digest:string, bytes:int}, result:?array<string,mixed>, error:?string, created_at:float, completed_at:?float}> $jobs */
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
        /** @var array<string, array{status:string, payload:array{digest:string, bytes:int}, result:?array<string,mixed>, error:?string, created_at:float, completed_at:?float}> $jobs */
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
        /** @var array<string, array{status:string, payload:array{digest:string, bytes:int}, result:?array<string,mixed>, error:?string, created_at:float, completed_at:?float}> $jobs */
        $jobs = $prop->getValue($queue);
        $keys = array_keys($jobs);
        return $keys[0] ?? null;
    }

    // ------------------------------------------------------------------
    // shell-S06 (php-dup-finder-shell-048): bounded memory for retained
    // payloads — the queue stores {digest, bytes}, never raw arrays.
    // Posture: the async leg serializes its `RUN <id> <json>` frame from
    // a request-local copy and writes it to the worker within the same
    // request before responding 202 — the queue row is never read back
    // for dispatch. The digest is pinned below to that exact wire
    // encoding (insertion-order bytes, key order included).
    // ------------------------------------------------------------------

    public function testGetSurfacesDescriptorOnlyNoRawPaths(): void
    {
        $queue = new JobQueue();
        $id = $queue->enqueue(['paths' => ['secret-listing-a', 'secret-listing-b']]);

        $job = $queue->get($id);

        $this->assertSame(['digest', 'bytes'], array_keys($job['payload']));
        $this->assertArrayNotHasKey('paths', $job['payload']);
        $this->assertStringNotContainsString(
            'secret-listing-a',
            json_encode($job, JSON_THROW_ON_ERROR)
        );
    }

    public function testPayloadDigestMatchesWireFrameEncodingAndDiscriminates(): void
    {
        $a = ['paths' => ['src/A.php'], 'mode' => 'strict'];
        $b = ['paths' => ['src/B.php'], 'mode' => 'strict'];

        // Same flags as Application::enqueueJob's worker dispatch frame.
        $frame = json_encode($a, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->assertSame(hash('sha256', $frame), JobQueue::payloadDigest($a));

        $queue = new JobQueue();
        $idA1 = $queue->enqueue($a);
        $idA2 = $queue->enqueue($a);
        $idB  = $queue->enqueue($b);

        $this->assertSame($queue->get($idA1)['payload'], $queue->get($idA2)['payload']);
        $this->assertNotSame(
            $queue->get($idA1)['payload']['digest'],
            $queue->get($idB)['payload']['digest']
        );
    }

    public function testHundredDistinctLargePayloadsRetainFixedBudget(): void
    {
        // Every enqueue gets its OWN large allocation: the 11-12 char
        // "/deep/dir$i/" repeated 26,214 times lands each wire frame at
        // 288,368-314,582 bytes (~282-307 KiB), so raw retention would
        // keep ≈29.7 MiB across the 100 jobs — far over the fixed 2 MiB
        // budget. The {digest, bytes} posture must settle at tens of
        // kilobytes. A distinct allocation per iteration is what makes
        // the budget assert discriminating: copy-on-write never collapses
        // separate strings, so reverting to raw retention fails HERE as
        // well as on the descriptor-keys asserts. (Any fixed large shape
        // meets the acceptance "16MiB-shape" literal — the discriminating
        // budget is what the pin is for.)
        $firstPayload = ['paths' => [str_repeat("/deep/dir0/", 26_214)]];
        $firstFrame = json_encode($firstPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $queue = new JobQueue();
        $start = memory_get_usage();

        $ids = [];
        for ($i = 0; $i < JobQueue::MAX_JOBS; $i++) {
            $ids[] = $queue->enqueue(['paths' => [str_repeat("/deep/dir$i/", 26_214)]]);
        }
        $retained = memory_get_usage() - $start;

        // Raw retention costs ≈29.7 MiB here (≈100 × ~300 KiB, one live
        // string per job); descriptors stay under the fixed 2 MiB budget.
        $this->assertLessThan(2 * 1024 * 1024, $retained);

        // Ids and statuses survive bounding, one per enqueued job.
        $this->assertCount(JobQueue::MAX_JOBS, array_unique($ids));
        foreach ($ids as $id) {
            $this->assertSame(JobQueue::STATUS_PENDING, $queue->get($id)['status']);
        }

        // The descriptor carries the full payload identity, not its bytes.
        $descriptor = $queue->get($ids[0])['payload'];
        $this->assertSame(['digest', 'bytes'], array_keys($descriptor));
        $this->assertSame(hash('sha256', $firstFrame), $descriptor['digest']);
        $this->assertSame(strlen($firstFrame), $descriptor['bytes']);
    }

    public function testDequeueAndCompletionLegSurvivePayloadBounding(): void
    {
        $queue = new JobQueue();
        $payload = ['paths' => ['src'], 'note' => 'worker runs the 202-time frame, not the queue row'];
        $id = $queue->enqueue($payload);

        $claimed = $queue->dequeue();
        $this->assertNotNull($claimed);
        $this->assertSame($id, $claimed['id']);
        $this->assertSame(JobQueue::payloadDigest($payload), $claimed['payload']['digest']);
        $this->assertSame(JobQueue::STATUS_RUNNING, $queue->status($id)['status']);

        $summary = ['files' => 3, 'blocks' => 12, 'clusters' => 1, 'config' => ['minBlockSize' => 5]];
        $queue->ack($id, $summary);

        $status = $queue->status($id);
        $this->assertSame(JobQueue::STATUS_COMPLETED, $status['status']);
        $this->assertSame($summary, $status['result']);
        $this->assertSame(
            JobQueue::payloadDigest($payload),
            $queue->get($id)['payload']['digest']
        );
    }

    public function testStatusAndListRoutesNeverExposePayload(): void
    {
        // The /api/jobs status JSON shape must stay byte-compatible.
        $queue = new JobQueue();
        $id = $queue->enqueue(['paths' => ['x']]);

        $this->assertSame(['status', 'result', 'error'], array_keys($queue->status($id)));

        $entry = $queue->list()[$id];
        $this->assertSame(['status', 'created_at', 'completed_at'], array_keys($entry));
    }

    public function testUnencodablePayloadFallsBackToBoundedDescriptorWithoutThrowing(): void
    {
        $queue = new JobQueue();
        $id = $queue->enqueue(['paths' => ['src'], 'limit' => INF]);

        $descriptor = $queue->get($id)['payload'];
        $this->assertSame(['digest', 'bytes'], array_keys($descriptor));
        $this->assertSame(64, strlen($descriptor['digest']));

        // The digest stays a stable identity of the lossy wire form.
        $this->assertSame(JobQueue::payloadDigest(['paths' => ['src'], 'limit' => INF]), $descriptor['digest']);

        // Cycle arm: a by-ref self-cycle never arrives through HTTP, but
        // enqueue() is public API and the fallback claims cycles too — so
        // a self-referencing payload must settle on a stable, BOUNDED
        // descriptor (partial-encode substitutes null), never throw or
        // chase the recursion.
        $cyclic = ['paths' => ['src']];
        $cyclic['mirror'] = &$cyclic;
        $cycleId = $queue->enqueue($cyclic);

        $cycleDescriptor = $queue->get($cycleId)['payload'];
        $this->assertSame(['digest', 'bytes'], array_keys($cycleDescriptor));
        $this->assertSame(64, strlen($cycleDescriptor['digest']));
        $this->assertSame(JobQueue::payloadDigest($cyclic), $cycleDescriptor['digest']);
        // `{"paths":["src"],"mirror":null}` — 31 bytes, bounded and
        // independent of the (infinite) logical depth of the structure.
        $this->assertSame(31, $cycleDescriptor['bytes']);
    }
}
