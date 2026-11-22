<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Parallel;

use PHPUnit\Framework\TestCase;
use Phpdup\Parallel\WorkerPool;

final class WorkerPoolTest extends TestCase
{
    public function testDetectsCpuCount(): void
    {
        $n = WorkerPool::detectCpuCount();
        $this->assertGreaterThanOrEqual(1, $n);
    }

    public function testDetectCpuCountMemoizes(): void
    {
        // Force a known env value to avoid static-cache pollution from previous tests.
        $original = getenv('PHPDUP_WORKERS');
        putenv('PHPDUP_WORKERS=7');

        try {
            $first  = WorkerPool::detectCpuCount();
            $second = WorkerPool::detectCpuCount();

            $this->assertSame($first, $second);
            $this->assertSame(7, $first);
        } finally {
            // Restore original env state so subsequent tests are unaffected.
            if ($original === false) {
                putenv('PHPDUP_WORKERS');
            } else {
                putenv("PHPDUP_WORKERS=$original");
            }
        }
    }

    public function testDetectCpuCountNeverCallsNprocWhenEnvSet(): void
    {
        // When PHPDUP_WORKERS is set to a value > 0, nproc must never be invoked.
        // Use a value (99) that cannot match any real machine CPU count.
        $original = getenv('PHPDUP_WORKERS');
        putenv('PHPDUP_WORKERS=99');

        try {
            $result = WorkerPool::detectCpuCount();

            // The result must be the env value (99), proving nproc was bypassed.
            // If nproc were called, the result would be the actual CPU count (≥1, most likely ≠99).
            $this->assertSame(99, $result);
        } finally {
            if ($original === false) {
                putenv('PHPDUP_WORKERS');
            } else {
                putenv("PHPDUP_WORKERS=$original");
            }
        }
    }

    public function testRunStreamingSerialFallbackForSmallInputs(): void
    {
        $pool = new WorkerPool(workers: 4);
        $gen = $pool->runStreaming([1, 2, 3], static fn(array $batch) => array_map(static fn($x) => $x * 2, $batch));
        $this->assertSame([2, 4, 6], iterator_to_array($gen, false));
    }

    public function testRunStreamingYieldsAcrossWorkers(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $pool = new WorkerPool(workers: 3);
        $gen = $pool->runStreaming(range(1, 30), static function (array $batch) {
            // Generator-returning task — streams each result as it's produced.
            foreach ($batch as $x) {
                yield $x * 10;
            }
        });
        $out = iterator_to_array($gen, false);
        sort($out);
        $expected = array_map(static fn($x) => $x * 10, range(1, 30));
        $this->assertSame($expected, $out);
    }

    public function testRunStreamingPropagatesChildException(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $pool = new WorkerPool(workers: 2);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Worker .* failed: boom/');
        $gen = $pool->runStreaming(range(1, 20), static function (array $batch) {
            throw new \RuntimeException('boom');
        });
        iterator_to_array($gen);
    }

    public function testRunStreamingHandlesArrayReturningTask(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $pool = new WorkerPool(workers: 2);
        // Array-returning task is also acceptable — runStreaming treats it as iterable.
        $gen = $pool->runStreaming(range(1, 16), static fn(array $batch) => array_map(static fn($x) => "item-$x", $batch));
        $out = iterator_to_array($gen, false);
        sort($out);
        $expected = array_map(static fn($x) => "item-$x", range(1, 16));
        sort($expected);
        $this->assertSame($expected, $out);
    }

    public function testRunStreamingWithEmptyInputCleansUpGracefully(): void
    {
        $pool = new WorkerPool(workers: 4);
        $taskCalled = false;
        $gen = $pool->runStreaming([], static function (array $batch) use (&$taskCalled) {
            $taskCalled = true;
            return $batch;
        });
        $result = iterator_to_array($gen, false);
        $this->assertFalse($taskCalled);
        $this->assertSame([], $result);
    }

    public function testRunStreamingWithSmallInputFallsBackToSerial(): void
    {
        $pool = new WorkerPool(workers: 4);
        $gen = $pool->runStreaming([1, 2, 3, 4, 5, 6, 7], static fn(array $batch) => array_map(static fn($x) => $x * 3, $batch));
        $out = iterator_to_array($gen, false);
        sort($out);
        $this->assertSame([3, 6, 9, 12, 15, 18, 21], $out);
    }

    // ── shell-S10: child lifecycle (shell-005, shell-028, shell-038) ─────
    //
    // Fork-zone pins below are hermetic: bounded child counts (the ceiling
    // test forces PHPDUP_WORKERS=2 so the fork set is 8 or fewer), no sleeps
    // and no timing races — every awaited event is a waitpid/EOF the pool
    // itself guarantees, so CI matrix runners cannot flake on scheduling.
    // Sole exception: the wedged-grace pin deliberately waits out a scaled
    // verification grace (setVerifyGraceScale seam) because a SIGSTOPped
    // child stays stopped — its outcome is monotone, only its clock is real.

    /** Restore PHPDUP_WORKERS exactly as it was (same idiom as the header tests). */
    private function withCpuEnv(string $value, callable $body): void
    {
        $original = getenv('PHPDUP_WORKERS');
        putenv("PHPDUP_WORKERS=$value");
        try {
            $body();
        } finally {
            if ($original === false) {
                putenv('PHPDUP_WORKERS');
            } else {
                putenv("PHPDUP_WORKERS=$original");
            }
        }
    }

    public function testWorkersCeilingAndClampFollowDetectedCpuCount(): void
    {
        $this->assertSame(4, WorkerPool::WORKERS_CEILING_MULTIPLIER);
        $this->withCpuEnv('2', function (): void {
            $this->assertSame(8, WorkerPool::workersCeiling());
            $this->assertSame(8, WorkerPool::clampWorkers(100000), 'oversized request clamps to the ceiling');
            $this->assertSame(5, WorkerPool::clampWorkers(5), 'a request at-or-below the ceiling passes through');
            $this->assertSame(8, WorkerPool::clampWorkers(8), 'the ceiling boundary itself passes through');
            $this->assertSame(1, WorkerPool::clampWorkers(0), 'auto never clamps below one');
            $this->assertSame(1, WorkerPool::clampWorkers(1), 'the serial posture is untouched by the clamp');
        });
    }

    public function testSigkilledChildMidChunkThrowsInsteadOfSilentDrop(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $this->withCpuEnv('2', function (): void {
            $pool = new WorkerPool(workers: 2);
            $seen = [];
            $caught = null;
            try {
                foreach ($pool->runStreaming(range(1, 16), static function (array $batch): \Generator {
                    if (in_array(1, $batch, true)) {
                        // The killer child: deliver two COMPLETE frames, then
                        // die like an OOM-killer would — no sentinel, no catch.
                        yield 'pre-kill-1';
                        yield 'pre-kill-2';
                        posix_kill(getmypid(), SIGKILL);
                        yield 'never-reached';
                    }
                    foreach ($batch as $x) {
                        yield 'ok-' . $x;
                    }
                }) as $record) {
                    $seen[] = $record;
                }
            } catch (\RuntimeException $e) {
                $caught = $e;
            }
            $this->assertNotNull($caught, 'a SIGKILLed child must fail loud, not drop its chunk silently');
            $this->assertMatchesRegularExpression('/Worker \d+ was killed by signal 9 \(SIGKILL\) mid-chunk/', $caught->getMessage());
            // Complete frames written before the death still arrive — the fix
            // fails loud WITHOUT dropping streamed data (the old code path
            // discarded the whole partial buffer).
            $this->assertContains('pre-kill-1', $seen);
            $this->assertContains('pre-kill-2', $seen);
            $this->assertNotContains('never-reached', $seen);
        });
    }

    public function testCleanChildExitWithoutSentinelThrows(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $this->withCpuEnv('2', function (): void {
            $pool = new WorkerPool(workers: 2);
            $caught = null;
            try {
                iterator_to_array($pool->runStreaming(range(1, 16), static function (array $batch) {
                    if (in_array(1, $batch, true)) {
                        // exit(0) short-circuits the sentinel write: status
                        // clean, contract violated — exactly the old silent
                        // drop the sentinel check retires.
                        exit(0);
                    }
                    return $batch;
                }), false);
            } catch (\RuntimeException $e) {
                $caught = $e;
            }
            $this->assertNotNull($caught, 'a sentinel-less exit(0) must throw');
            $this->assertMatchesRegularExpression('/Worker \d+ exited cleanly without its __done sentinel/', $caught->getMessage());
        });
    }

    public function testNonzeroChildExitWithoutFrameThrows(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $this->withCpuEnv('2', function (): void {
            $pool = new WorkerPool(workers: 2);
            $caught = null;
            try {
                iterator_to_array($pool->runStreaming(range(1, 16), static function (array $batch) {
                    if (in_array(1, $batch, true)) {
                        exit(3); // dies nonzero with no __error frame
                    }
                    return $batch;
                }), false);
            } catch (\RuntimeException $e) {
                $caught = $e;
            }
            $this->assertNotNull($caught, 'a nonzero exit with no __error frame must throw');
            $this->assertMatchesRegularExpression('/Worker \d+ exited with status 3 mid-chunk/', $caught->getMessage());
        });
    }

    /**
     * shell-S10 fix (review L-2): a child that stops on the way out — AFTER
     * its __done sentinel, so the drain loop ends cleanly and the exit-status
     * verification pass is what meets it — must fail loud within the bounded
     * collect escalation, never as an infinite blocking wait. The pre-fix
     * single pcntl_waitpid on the happy path hangs forever on exactly this
     * shape (a SIGSTOPped child cannot acknowledge SIGTERM; only the SIGKILL
     * leg, which the kernel delivers through the stop, clears it).
     */
    public function testWedgedChildAfterSentinelFailsLoudWithinBoundedGrace(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $this->withCpuEnv('2', function (): void {
            $pool   = new WorkerPool(workers: 2);
            $start  = microtime(true);
            $caught = null;
            // Scale the production graces (5/2/2 s) down to 0.5/0.2/0.2 s.
            WorkerPool::setVerifyGraceScale(0.1);
            try {
                iterator_to_array($pool->runStreaming(range(1, 16), static function (array $batch): array {
                    if (in_array(1, $batch, true)) {
                        // exit(0) runs shutdown functions AFTER the harness
                        // wrote __done and closed the child socket: the
                        // parent drains this stream through the sentinel, so
                        // the stopped child is met only in the verification
                        // pass — the exact L-2 surface.
                        register_shutdown_function(static function (): void {
                            posix_kill(getmypid(), SIGSTOP);
                        });
                    }
                    return $batch;
                }), false);
            } catch (\RuntimeException $e) {
                $caught = $e;
            } finally {
                WorkerPool::setVerifyGraceScale(null);
            }
            $elapsed = microtime(true) - $start;
            $this->assertNotNull($caught, 'a wedged post-sentinel child must fail loud, not block forever');
            $this->assertMatchesRegularExpression(
                '/was killed by signal \d+ \(SIG(?:TERM|KILL)\) after its __done sentinel/',
                $caught->getMessage(),
                'the escalation must reap the child and name the killing signal honestly'
            );
            // The escalated windows total 0.9 s. A regression to the unbounded
            // blocking wait cannot fail THIS assertion — it hangs the test and
            // turns red via the runner's outer timeout instead; the 15 s
            // ceiling only absorbs slow-CI scheduling jitter on the live path.
            $this->assertLessThan(15.0, $elapsed, 'bounded escalation must land far inside an infinite hang');
        });
    }

    public function testSocketPairFailureAfterPartialSpawnReapsChildrenAndLeaksNoFds(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        if (!is_dir('/proc/self/fd')) {
            $this->markTestSkipped('needs /proc/self/fd for the fd-litter census');
        }
        $this->withCpuEnv('2', function (): void {
            $fdsBefore = count(scandir('/proc/self/fd'));
            $calls = 0;
            // Real pairs for the first two workers, then a forced failure at
            // worker 3 of 4 — shell-028's exact "mid-loop throw with a partial
            // live set" shape, driven through the seam.
            WorkerPool::setSocketPairFactory(static function () use (&$calls) {
                $calls++;
                if ($calls > 2) {
                    return false;
                }
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                return $pair === false ? false : [$pair[0], $pair[1]];
            });
            $caught = null;
            try {
                $pool = new WorkerPool(workers: 4);
                try {
                    iterator_to_array($pool->runStreaming(range(1, 32), static fn(array $batch): array => $batch), false);
                } catch (\RuntimeException $e) {
                    $caught = $e;
                }
            } finally {
                WorkerPool::setSocketPairFactory(null);
            }
            $this->assertSame(3, $calls, 'the fork loop must have reached worker 3 before failing');
            $this->assertNotNull($caught, 'a forced socketpair failure must surface to the consumer');
            $this->assertMatchesRegularExpression(
                '/stream_socket_pair failed before worker 3 of 4 could start \(2 already spawned\)/',
                $caught->getMessage(),
            );
            // Parent side: both registered fds closed by the finally; the
            // failed iteration opened none; the fork-failure branch closes
            // its own pair. The count must be back at baseline.
            $this->assertSame($fdsBefore, count(scandir('/proc/self/fd')), 'no parent fd leaked by the partial fork');
            // Child side: nothing waitable survives — the finally SIGTERMed
            // and blocking-reaped the two spawned children (no zombies, no
            // orphans). Same drain-and-fail contract WireTest pins for the
            // mid-stream throw path.
            $lateReaped = [];
            for ($i = 0; $i < 64; $i++) {
                $status = 0;
                $pid    = pcntl_waitpid(-1, $status, WNOHANG);
                if ($pid === -1) {
                    break; // ECHILD: the pool reaped everything it spawned
                }
                if ($pid === 0) {
                    usleep(5000);
                    continue;
                }
                $lateReaped[] = $pid;
            }
            $this->assertSame([], $lateReaped, 'partial fork set must be fully reaped before the throw surfaces');
        });
    }

    public function testOversizedWorkersRequestIsClampedAtThePool(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $this->withCpuEnv('2', function (): void {
            // PHPDUP_WORKERS=2 ⇒ ceiling 8. 24 items with a 100000 request:
            // unclamped that is 24 size-1 children ALL AT ONCE (shell-038);
            // clamped: exactly 8 children, chunks of ceil(24/8) = 3.
            $pool = new WorkerPool(workers: 100000);
            $rows = iterator_to_array(
                $pool->runStreaming(range(1, 24), static fn(array $batch): array => [getmypid() . ':' . count($batch)]),
                false,
            );
            $this->assertCount(8, $rows, 'effective child count must equal the clamped ceiling');
            $pids = [];
            foreach ($rows as $row) {
                [$pid, $size] = explode(':', $row);
                $pids[(int)$pid] = true;
                $this->assertSame(3, (int)$size, 'fewer, bigger chunks — not one child per item');
            }
            $this->assertCount(8, $pids, 'each chunk ran in its own process');
        });
    }

    public function testWorkersRequestBelowCeilingIsNeverClamped(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $this->withCpuEnv('2', function (): void {
            // Same shape as the clamp test with workers=3 (< ceiling 8):
            // the clamp must be inert for sane requests.
            $pool = new WorkerPool(workers: 3);
            $rows = iterator_to_array(
                $pool->runStreaming(range(1, 24), static fn(array $batch): array => [getmypid() . ':' . count($batch)]),
                false,
            );
            $this->assertCount(3, $rows);
            $pids = [];
            foreach ($rows as $row) {
                [$pid, $size] = explode(':', $row);
                $pids[(int)$pid] = true;
                $this->assertSame(8, (int)$size);
            }
            $this->assertCount(3, $pids);
        });
    }

    // ── once-S77 (once-093 / once-110): portable CPU detection ────────────
    //
    // The probe chain must never shell out (the retired shape was
    // shell_exec('nproc 2>/dev/null'): Linux-only advice, host topology in
    // quota'd containers, and a redirect cmd doesn't honor on Windows).
    // "Container with a cpu.max limit → detected == quota" is pinned here
    // through fixture /sys + /proc strings installed via the probe seam;
    // PHPDUP_WORKERS semantics are pinned as SEED-NOT-OVERRIDE — the truth
    // of `$this->workers > 0 ? $this->workers : detectCpuCount()`.

    /**
     * Run a detection scenario against fixture /sys + /proc strings.
     * PHPDUP_WORKERS is cleared so the env seed cannot short-circuit the
     * probe chain; the seam and the environment are restored in finally.
     *
     * @param array<string, string> $files path → fixture contents (anything
     *                                     not listed reads as null/absent)
     */
    private function withProbeFixtures(array $files, callable $scenario): void
    {
        $original = getenv('PHPDUP_WORKERS');
        putenv('PHPDUP_WORKERS');
        WorkerPool::setSysFileReader(static fn(string $path): ?string => $files[$path] ?? null);
        try {
            $scenario();
        } finally {
            WorkerPool::setSysFileReader(null);
            if ($original === false) {
                putenv('PHPDUP_WORKERS');
            } else {
                putenv("PHPDUP_WORKERS=$original");
            }
        }
    }

    /** @return string a /proc/cpuinfo body with $n "processor\t:" entries */
    private static function cpuinfoFixture(int $n): string
    {
        $out = '';
        for ($i = 0; $i < $n; $i++) {
            $out .= "processor\t: $i\nvendor_id\t: FakeCPU\nmodel name\t: fixture\n\n";
        }
        return $out;
    }

    public function testCpuDetectionNeverShellsOut(): void
    {
        // Static-source census (once-093): the detection path in
        // WorkerPool.php may not invoke a shell — prose mentions inside
        // comments do not count, code lines do.
        $lines  = file(__DIR__ . '/../../../src/Parallel/WorkerPool.php');
        $this->assertNotFalse($lines, 'WorkerPool source must be readable for the census');
        foreach ($lines as $no => $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '/*')) {
                continue; // comment lines carry the retired-shape prose
            }
            $this->assertSame(
                0,
                preg_match('/\b(shell_exec|proc_open|popen|passthru|system|exec)\s*\(/', $line),
                'line ' . ($no + 1) . ' invokes a shell execution function: ' . trim($line)
            );
        }
    }

    public function testCgroupV2QuotaBeatsHostTopology(): void
    {
        // Acceptance scenario: container with cpu.max = 2 CPUs on a
        // 64-processor host → detected == the QUOTA, not the host.
        $this->withProbeFixtures([
            '/sys/fs/cgroup/cpu.max' => "200000 100000\n",
            '/proc/cpuinfo'          => self::cpuinfoFixture(64),
        ], function (): void {
            $this->assertSame(2, WorkerPool::detectCpuCount());
            $this->assertSame(8, WorkerPool::workersCeiling(), 'the ceiling rides the quota-detected count');
        });
    }

    public function testCgroupV2PartialQuotaRoundsUp(): void
    {
        $this->withProbeFixtures([
            '/sys/fs/cgroup/cpu.max' => "180000 100000\n", // 1.8 CPUs
            '/proc/cpuinfo'          => self::cpuinfoFixture(64),
        ], function (): void {
            $this->assertSame(2, WorkerPool::detectCpuCount(), '1.8 quota CPUs become 2, not 1');
        });
    }

    public function testCgroupV2UnlimitedFallsThroughToCpuinfo(): void
    {
        $this->withProbeFixtures([
            '/sys/fs/cgroup/cpu.max' => "max 100000\n", // unlimited
            '/proc/cpuinfo'          => self::cpuinfoFixture(3),
        ], function (): void {
            $this->assertSame(3, WorkerPool::detectCpuCount());
        });
    }

    public function testCgroupV1QuotaAppliesWhenV2Absent(): void
    {
        $this->withProbeFixtures([
            '/sys/fs/cgroup/cpu/cpu.cfs_quota_us'  => "150000\n",
            '/sys/fs/cgroup/cpu/cpu.cfs_period_us' => "100000\n",
            '/proc/cpuinfo'                        => self::cpuinfoFixture(96),
        ], function (): void {
            $this->assertSame(2, WorkerPool::detectCpuCount(), 'v1 pair 1.5 CPUs rounds up to 2');
        });
    }

    public function testCgroupV1UnlimitedQuotaFallsThrough(): void
    {
        $this->withProbeFixtures([
            '/sys/fs/cgroup/cpu/cpu.cfs_quota_us'  => "-1\n", // unlimited
            '/sys/fs/cgroup/cpu/cpu.cfs_period_us' => "100000\n",
            '/proc/cpuinfo'                        => self::cpuinfoFixture(8),
        ], function (): void {
            $this->assertSame(8, WorkerPool::detectCpuCount());
        });
    }

    public function testWindowsProbeIsPortableApiNoShell(): void
    {
        // Windows path: no /proc, no /sys — NUMBER_OF_PROCESSORS answers
        // through plain getenv(), never a `nproc 2>/dev/null` cmd call.
        $original = getenv('NUMBER_OF_PROCESSORS');
        putenv('NUMBER_OF_PROCESSORS=12');
        try {
            $this->withProbeFixtures([], function (): void {
                $this->assertSame(12, WorkerPool::detectCpuCount());
            });
        } finally {
            if ($original === false) {
                putenv('NUMBER_OF_PROCESSORS');
            } else {
                putenv("NUMBER_OF_PROCESSORS=$original");
            }
        }
    }

    public function testDeclaredFallbackWhenNothingVisible(): void
    {
        // macOS/FreeBSD host with no quota: the OLD code silently answered 4
        // after a pointless shell_exec; the new chain answers the DECLARED
        // CPU_FALLBACK_COUNT after every portable probe came up empty.
        $original = getenv('NUMBER_OF_PROCESSORS');
        putenv('NUMBER_OF_PROCESSORS');
        try {
            $this->withProbeFixtures([], function (): void {
                $this->assertSame(WorkerPool::CPU_FALLBACK_COUNT, WorkerPool::detectCpuCount());
            });
        } finally {
            if ($original === false) {
                putenv('NUMBER_OF_PROCESSORS');
            } else {
                putenv("NUMBER_OF_PROCESSORS=$original");
            }
        }
    }

    public function testSeamDetectionIsNeverMemoized(): void
    {
        // Fixture runs must not poison the production static cache: the
        // value must follow the seam contents, not the first answer.
        $this->withProbeFixtures(
            ['/sys/fs/cgroup/cpu.max' => "200000 100000\n"],
            function (): void {
                $this->assertSame(2, WorkerPool::detectCpuCount());
                WorkerPool::setSysFileReader(
                    static fn(string $path): ?string => $path === '/sys/fs/cgroup/cpu.max' ? "1000000 100000\n" : null
                );
                $this->assertSame(10, WorkerPool::detectCpuCount(), 'a re-installed seam must re-probe, not replay');
            }
        );
    }

    public function testCgroupV2MaxParserCases(): void
    {
        $this->assertSame(0, WorkerPool::parseCgroupV2Max(null), 'unreadable file reads as no-quota');
        $this->assertSame(0, WorkerPool::parseCgroupV2Max(''), 'empty body is malformed, not zero CPUs');
        $this->assertSame(0, WorkerPool::parseCgroupV2Max("max 100000\n"), 'unlimited');
        $this->assertSame(0, WorkerPool::parseCgroupV2Max("0 100000\n"), 'non-positive quota is no quota');
        $this->assertSame(0, WorkerPool::parseCgroupV2Max("200000 0\n"), 'zero period is garbage');
        $this->assertSame(0, WorkerPool::parseCgroupV2Max("nonsense\n"), 'malformed bodies keep probing');
        $this->assertSame(1, WorkerPool::parseCgroupV2Max("100000 100000\n"), 'whole CPU');
        $this->assertSame(1, WorkerPool::parseCgroupV2Max("25000 100000\n"), 'quarter CPU rounds up to 1');
        $this->assertSame(3, WorkerPool::parseCgroupV2Max("250000 100000\n"), '2.5 rounds up to 3');
    }

    public function testCgroupV1QuotaParserCases(): void
    {
        $this->assertSame(0, WorkerPool::parseCgroupV1Quota(null, '100000'), 'missing quota file');
        $this->assertSame(0, WorkerPool::parseCgroupV1Quota('-1', null), 'missing period file');
        $this->assertSame(0, WorkerPool::parseCgroupV1Quota('-1', '100000'), 'unlimited v1 quota');
        $this->assertSame(0, WorkerPool::parseCgroupV1Quota('150000', '0'), 'zero period is garbage');
        $this->assertSame(0, WorkerPool::parseCgroupV1Quota('nonsense', '100000'), 'non-numeric is no quota');
        $this->assertSame(2, WorkerPool::parseCgroupV1Quota("150000\n", "100000\n"), '1.5 rounds up');
        $this->assertSame(4, WorkerPool::parseCgroupV1Quota("400000", "100000"), 'whole multiple stays whole');
    }

    public function testParseCpuinfoCases(): void
    {
        $this->assertSame(0, WorkerPool::parseCpuinfo(null), 'unreadable /proc/cpuinfo');
        $this->assertSame(0, WorkerPool::parseCpuinfo('vendor_id : no processors here'), 'no entries');
        $this->assertSame(3, WorkerPool::parseCpuinfo(self::cpuinfoFixture(3)));
    }

    public function testEnvSeedNeverOverridesExplicitWorkers(): void
    {
        // once-110 seed-not-override: PHPDUP_WORKERS seeds the AUTO count
        // (and the ceiling), but an explicit request always wins over it.
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $this->withCpuEnv('2', function (): void {
            // Seed says 2; the explicit request says 5 (≤ ceiling 8). If
            // the seed OVERRODE, we would fork 2 children of 8; truth: 5
            // children of 3 over 15 items.
            $pool = new WorkerPool(workers: 5);
            $rows = iterator_to_array(
                $pool->runStreaming(range(1, 15), static fn(array $batch): array => [getmypid() . ':' . count($batch)]),
                false,
            );
            $this->assertCount(5, $rows, 'the explicit workers=5 must beat the PHPDUP_WORKERS=2 seed');
            $pids = [];
            foreach ($rows as $row) {
                [$pid, $size] = explode(':', $row);
                $pids[(int)$pid] = true;
                $this->assertSame(3, (int)$size);
            }
            $this->assertCount(5, $pids, 'five real children — proof the request was honoured');
        });
    }
}
