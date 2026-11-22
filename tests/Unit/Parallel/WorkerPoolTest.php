<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Parallel;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Persistence\SerializedClassAllowList;
use Phpdup\Pipeline\PipelineState;
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
                // once-S77 r1 LOW-1: pin the VALUE as well as the chain answer —
                // the constant-relative assert below is tautological on its own,
                // so without this line flipping the constant keeps the suite
                // green while docs/CLI.md "Fallback `4`" silently drifts.
                $this->assertSame(4, WorkerPool::CPU_FALLBACK_COUNT);
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

    // ── shell-S11 (php-dup-finder-shell-011): the reworked decode guard ───
    //
    // decodeFrame is private static; the reflection seam is the pattern
    // WorkerPoolWireTest already uses for the corrupt-frame path. These
    // pins exercise the GUARD CHAIN (class-header gate → unserialize →
    // graph walk on suspect frames) without forking — the end-to-end
    // fork regressions for the same verdicts live in WorkerPoolWireTest
    // (top-level and nested-in-Block poison), and they must stay green:
    // the message still carries 'allow-list' and the offending class
    // name.

    /** @param list<class-string> $allowList */
    private function invokeDecodeFrame(int $idx, string $payload, array $allowList): mixed
    {
        $ref = new \ReflectionMethod(WorkerPool::class, 'decodeFrame');
        $ref->setAccessible(true);
        return $ref->invoke(null, $idx, $payload, $allowList);
    }

    public function testDecodeFrameAdmitsRealGraphWireRecord(): void
    {
        // The hot path acceptance (shell-011): a genuine Block+AST
        // frame has every header on the resolvable allow-list, so the
        // gate clears it and the graph walk is skipped entirely — the
        // record must still restore byte-faithfully, DAG sharing
        // included (the R: refs point back at headers the gate saw).
        $parser = new \PhpParser\ParserFactory();
        $ast    = $parser->createForNewestSupportedVersion()
            ->parse("<?php\n// c\nfunction greet(\$n) { /* inline */ echo \$n; }\n") ?: [];
        $blocks = (new \Phpdup\Extraction\BlockExtractor(minSize: 1))->extract('s11-probe.php', $ast);
        $this->assertNotEmpty($blocks);
        $blocks[0]->canonical = $ast[0];
        // DAG-shaped frame: the same block twice → R: reference markers.
        $payload = serialize(['type' => 'pair', 'a' => $blocks[0], 'b' => $blocks[0]]);

        $record = $this->invokeDecodeFrame(0, $payload, SerializedClassAllowList::blockCacheClasses());
        $this->assertIsArray($record);
        $this->assertInstanceOf(\Phpdup\Extraction\Block::class, $record['a']);
        $this->assertSame($record['a'], $record['b'], 'the shared DAG node must restore shared');
    }

    public function testDecodeFrameRefusesPoisonNestedThreeDeepByName(): void
    {
        $payload = serialize(['rows' => ['block' => ['x' => ['y' => ['z' => (object) ['evil' => new \ArrayObject()]]]]]]);
        try {
            $this->invokeDecodeFrame(3, $payload, SerializedClassAllowList::blockCacheClasses());
            $this->fail('nested blocked class must throw');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/allow-list/', $e->getMessage());
            $this->assertStringContainsString('stdClass', $e->getMessage(), 'the first blocked class the walk meets is named');
            $this->assertStringContainsString('Worker 3', $e->getMessage());
        }
    }

    public function testDecodeFrameRefusesTypedSlotPoisonDescriptivelyNotAsRawTypeError(): void
    {
        // Pre-S11, a foreign object dressed as Block::$range (typed
        // LineRange) escaped @unserialize as a raw "Cannot assign
        // __PHP_Incomplete_Class to property" TypeError from mid-decode.
        // The stdClass header is spelled literally in the bytes, so the
        // gate flags the frame BEFORE the walk and the refusal names it
        // descriptively — same fail-loud posture, no type-system leak.
        $block = new \Phpdup\Extraction\Block(
            file: 'x.php',
            range: new \Phpdup\Util\LineRange(1, 2),
            kind: 'function',
            namespace: null,
            class: null,
            name: 'f',
            ast: new \PhpParser\Node\Stmt\Nop(),
        );
        $block->canonical = new \PhpParser\Node\Stmt\Nop();
        $real = serialize([$block]);
        $evil = serialize(new \stdClass());
        $needle = 'O:21:"Phpdup\Util\LineRange":2:{s:5:"start";i:1;s:3:"end";i:2;}';
        $this->assertStringContainsString($needle, $real, 'fixture: the typed slot must serialize in the expected form');
        $forged = str_replace($needle, $evil, $real);

        try {
            $this->invokeDecodeFrame(1, $forged, SerializedClassAllowList::blockCacheClasses());
            $this->fail('typed-slot poison must throw');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/allow-list/', $e->getMessage());
            $this->assertStringContainsString('stdClass', $e->getMessage());
        } catch (\TypeError $e) {
            $this->fail('raw TypeError escaped the guard — the suspect path must name the frame descriptively: ' . $e->getMessage());
        }
    }

    public function testDecodeFrameStillRefusesCorruptFramesAndPassesSentinels(): void
    {
        // S10 early-out interaction (shell-011 acceptance note): the
        // reworked guard must not swallow the corrupt-frame path, and
        // the __done/__error sentinel shapes are plain scalar arrays —
        // zero class headers, so the gate clears them and the drain
        // loop's control-frame inspection still happens first.
        try {
            $this->invokeDecodeFrame(2, "\xff\xfe not serialized", SerializedClassAllowList::blockCacheClasses());
            $this->fail('corrupt frame must throw');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/failed to decode/', $e->getMessage());
        }
        foreach ([['__done' => true], ['__error' => 'boom']] as $sentinel) {
            $record = $this->invokeDecodeFrame(0, serialize($sentinel), SerializedClassAllowList::blockCacheClasses());
            $this->assertSame($sentinel, $record, 'control frames sail through both screens');
        }
    }

    public function testDecodeFrameExoticStringTagsKeepBaselineVerdicts(): void
    {
        // Exotic S: tag payloads (PHP's decoder accepts them) reach the
        // BASELINE verdicts through the new chain: a clean one spells no
        // class header at all, so the gate clears it straight to the
        // decode; one carrying a blocked object still spells the O:
        // header literally — the gate flags it and the authoritative
        // graph walk owns the refusal.
        $allow = SerializedClassAllowList::blockCacheClasses();
        $count = 0;
        $exotic = str_replace('s:', 'S:', serialize(['t' => 'pair', 'msg' => 'hello']), $count);
        $this->assertGreaterThan(0, $count);
        $record = $this->invokeDecodeFrame(0, $exotic, $allow);
        $this->assertSame(['t' => 'pair', 'msg' => 'hello'], $record, 'gate must admit the honest exotic form');

        // Same exotic framing, but the payload carries a blocked object:
        // the graph walk (not the gate's say-so) owns the refusal.
        $evilCount = 0;
        $evilExotic = str_replace('s:', 'S:', serialize(['obj' => new \stdClass()]), $evilCount);
        $this->assertGreaterThan(0, $evilCount);
        try {
            $this->invokeDecodeFrame(4, $evilExotic, $allow);
            $this->fail('gate-flagged poison must still be refused');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/allow-list/', $e->getMessage());
            $this->assertStringContainsString('stdClass', $e->getMessage());
        }
    }

    public function testDecodeFrameAcceptsSerializedLookalikeBaitInStrings(): void
    {
        // shell-011 parity arm: a frame whose only 'O:8:"stdClass"' is
        // TEXT inside a string field (exactly what a comment token in a
        // real AST can carry) trips the header regex, gets flagged by
        // the gate, and the walk then clears it — the baseline chain
        // walked the same graph and yielded. A refusal here would void
        // honest frames (and the corpus proof is the Golden suite).
        $allow = SerializedClassAllowList::blockCacheClasses();
        $frame = ['type' => 'pair', 'note' => 'O:8:"stdClass":0:{} lives only as text here'];
        $record = $this->invokeDecodeFrame(0, serialize($frame), $allow);
        $this->assertSame($frame, $record);
    }

    // ── shell-S12 (php-dup-finder-shell-041): cooperative mid-stream cancel ──
    //
    // Before shell-S12 the select loop was cancellation-unaware: a stalled
    // child left the pool spinning 1-second stream_select timeouts forever,
    // because the consumer's state->cancelled poll lives at a yield
    // boundary that a stall never reaches. The optional $cancel probe is
    // now checked at the entry (start NOTHING) and at every select pulse
    // (end QUIETLY: verification pass skipped, children SIGTERMed + reaped
    // by the finally). A null or never-armed probe keeps the historic
    // behaviour byte-for-byte — the whole pre-existing section above is
    // that half of the pin.

    /**
     * Census pattern reused from the shell-S10 partial-spawn pin: after a
     * cancelled run nothing waitable may survive — the finally TERMed and
     * blocking-reaped every child it forked.
     */
    private function assertPoolLeftNoStrayChildren(): void
    {
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
        $this->assertSame([], $lateReaped, 'cancelled children must be SIGTERMed and reaped before the return surfaces');
    }

    public function testPreArmedCancelStartsNothingAtAll(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $this->withCpuEnv('2', function (): void {
            $marker = sys_get_temp_dir() . '/shell-s12-prearm-' . bin2hex(random_bytes(6));
            $pool   = new WorkerPool(workers: 2);
            $seen   = iterator_to_array($pool->runStreaming(
                range(1, 16),
                static function (array $batch) use ($marker): \Generator {
                    // Child-side proof of life: ANY fork that runs this task
                    // — the serial fallback or a stalled child — touches it.
                    touch($marker);
                    yield 'should-never-stream';
                },
                static fn(): bool => true,
            ), false);
            $this->assertSame([], $seen, 'a pre-armed cancel must end the stream with zero records');
            $this->assertFileDoesNotExist($marker, 'not even one child (nor the serial fallback) may start');
            $this->assertPoolLeftNoStrayChildren();
            @unlink($marker);
        });
    }

    public function testStalledChildCancelledMidStreamEndsQuietlyAndReaps(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $this->withCpuEnv('2', function (): void {
            $state = new PipelineState(Config::defaults([sys_get_temp_dir()]));
            $pool  = new WorkerPool(workers: 2);
            $start = microtime(true);
            $seen  = [];
            try {
                foreach ($pool->runStreaming(
                    range(1, 16),
                    static function (array $batch): \Generator {
                        if (in_array(1, $batch, true)) {
                            // The one record that proves streaming was live.
                            yield 'pre-stall';
                        }
                        // Both children park here forever: no EOF, no
                        // sentinel, no further frames — the shell-041 stall.
                        while (true) {
                            usleep(10000);
                        }
                    },
                    static function () use ($state): bool {
                        return $state->cancelled;
                    },
                ) as $record) {
                    $seen[] = $record;
                    // The exact CLI/TUI shape: SignalHandler's first Ctrl-C
                    // flips state->cancelled; the pool owns noticing it
                    // while the children are wedged.
                    $state->cancelled = true;
                }
            } catch (\Throwable $e) {
                $this->fail('a deliberate cancel must end the generator without error, got ' . $e::class . ': ' . $e->getMessage());
            }
            $elapsed = microtime(true) - $start;
            $this->assertSame(['pre-stall'], $seen, 'streaming halts at the cancel, records before it survive');
            $this->assertLessThan(15.0, $elapsed, 'the pool returns promptly instead of waiting on the wedged children');
            $this->assertTrue($state->cancelled, 'the exercise went through the real state->cancelled field');
            $this->assertPoolLeftNoStrayChildren();
        });
    }

    public function testSelectTimeoutPulseCarriesTheCancelWithZeroRecords(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $this->withCpuEnv('2', function (): void {
            $pool     = new WorkerPool(workers: 2);
            $deadline = microtime(true) + 0.1; // already past at the first 1 s pulse
            $start    = microtime(true);
            $seen     = iterator_to_array($pool->runStreaming(
                range(1, 16),
                static function (array $batch): \Generator {
                    while (true) {
                        // Never a byte on the wire — the pure stall case.
                        usleep(10000);
                        yield from [];
                    }
                },
                static function () use ($deadline): bool {
                    return microtime(true) >= $deadline;
                },
            ), false);
            $elapsed = microtime(true) - $start;
            $this->assertSame([], $seen, 'a stream cancelled from a stall yields nothing');
            $this->assertLessThan(15.0, $elapsed, 'the 1 s timeout arm must carry the cancel pulse');
            $this->assertPoolLeftNoStrayChildren();
        });
    }

    public function testNeverFiringCancelProbeKeepsTheHistoricHappyPath(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $this->withCpuEnv('2', function (): void {
            $pool = new WorkerPool(workers: 2);
            $rows = iterator_to_array($pool->runStreaming(
                range(1, 16),
                static function (array $batch): array {
                    $out = [];
                    foreach ($batch as $x) {
                        $out[] = 'ok-' . $x;
                    }
                    return $out;
                },
                static fn(): bool => false,
            ), false);
            sort($rows);
            $expect = array_map(static fn(int $x): string => 'ok-' . $x, range(1, 16));
            sort($expect);
            $this->assertSame($expect, $rows, 'a wired-but-never-armed probe changes nothing: all records stream, verification keeps its historic quiet success');
        });
    }
}
