<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Parallel;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Persistence\SerializedClassAllowList;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
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
        // No setAccessible(): it has been a no-op since PHP 8.1 and is
        // deprecated since 8.5 (CI-85 leg tests/Unit/Parallel/WorkerPoolTest.php:691).
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

    public function testEveryStageHandsThePoolAStateCancelledProbe(): void
    {
        // shell-S12 r1 (LOW-1): code-inspection pin for the stage-side leg.
        // The behavioural tests above prove the POOL reacts to a probe; they
        // are blind to whether the stages still hand it one — reverting any
        // stage call site to the historic 2-arg foreach kept the whole Unit
        // suite green (reviewer mutant M2). Same doctrine as the shell-S03
        // n-3 positional pin that closed a probe-only wiring: assert the
        // CALL SHAPE over the source — three arguments, the third bound to
        // a closure reading the real state->cancelled field — never line
        // numbers, so shell-S37's re-indent pass over these regions passes
        // untouched while any silent probe drop goes red here.
        foreach ([ClusterStage::class, PreprocessStage::class, RefactorStage::class] as $stageClass) {
            $short = substr($stageClass, (int)strrpos($stageClass, '\\') + 1);
            $src   = (string)file_get_contents((string)(new \ReflectionClass($stageClass))->getFileName());

            $open = strpos($src, '->runStreaming(');
            $this->assertIsInt($open, $short . ': expected a WorkerPool::runStreaming call site');
            $this->assertFalse(strpos($src, '->runStreaming(', $open + 1),
                $short . ': this pin watches a single pool call site — update it deliberately if the stage ever gains another');
            $open += strlen('->runStreaming(');

            [$close, $args] = $this->runStreamingCallArguments($src, $open);
            $this->assertCount(3, $args, $short . ': the runStreaming call must pass a cancellation probe as its third argument — a 2-arg call silently restores the shell-041 stall-spin (S12 r1 mutant M2)');

            $probe = trim($args[2]);
            $this->assertNotSame('', $probe, $short . ': third argument may not be empty');
            $this->assertNotSame('null', strtolower($probe), $short . ': a literal null probe is the stall-spin behind 3-arg clothing');

            if (preg_match('/\A\$[a-zA-Z_]\w*\z/', $probe, $m) === 1) {
                // Named probe: the variable must be bound — before the
                // call — to a closure over the state this stage pumps.
                $assign = strpos($src, $m[0] . ' =');
                if ($assign === false) {
                    $assign = strpos($src, $m[0] . '=');
                }
                $this->assertIsInt($assign, $short . ': probe ' . $m[0] . ' is never assigned at its call site');
                $this->assertLessThan($open, $assign, $short . ': probe ' . $m[0] . ' must be assigned before the call');
                $binding = substr($src, $assign, $close - $assign);
            } else {
                $binding = $probe;
            }
            $this->assertMatchesRegularExpression('/\bfunction\b|\bfn\b/', $binding, $short . ': the probe must be a closure');
            $this->assertStringContainsString('$state->cancelled', $binding,
                $short . ': the probe must read the real state->cancelled field — the SignalHandler/TUI contract the pool is meant to see');
        }
    }

    /**
     * Walk the argument list of a call whose opening paren ends at $open,
     * tracking bracket depth (string literals skipped whole) and cutting
     * the raw texts at depth-1 commas. Call-shape framing on purpose:
     * newlines, re-indents and nested calls cannot confuse it.
     *
     * @return array{0:int,1:string[]} closing paren offset, argument texts
     */
    private function runStreamingCallArguments(string $src, int $open): array
    {
        $args    = [];
        $current = '';
        $depth   = 1;
        $len     = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            $c = $src[$i];
            if ($c === "'" || $c === '"') {
                $current .= $c;
                for ($i++; $i < $len; $i++) {
                    $current .= $src[$i];
                    if ($src[$i] === '\\' && $i + 1 < $len) {
                        $current .= $src[++$i];
                    } elseif ($src[$i] === $c) {
                        break;
                    }
                }
                continue;
            }
            if ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($c === ',' && $depth === 1) {
                $args[]    = $current;
                $current   = '';
                continue;
            }
            $current .= $c;
        }
        if ($args !== [] || trim($current) !== '') {
            $args[] = $current;
        }

        return [$i, $args];
    }

    // ── shell-S13 (php-dup-finder-shell-050): linear partial-write handling ──
    //
    // writeRecord owns the encoder side of the framed worker stream (the
    // decodeFrame docblock names it). Pre-S13 it re-sliced the whole
    // shrinking remainder into every fwrite (`substr($framed, $written)`)
    // and treated any short OR zero return as fatal — under a slowly
    // draining parent that was quadratic byte churn plus, on a non-blocking
    // stream, a silent frame truncation. The pins below walk each arm the
    // rewrite added: the fixed-window cursor's byte-honest delivery and the
    // blocking-mode refusal posture (a userland sink: deterministic, no
    // kernel internals), the dead-peer stop (error taxonomy deliberately
    // UNCHANGED — the pool's EOF/sentinel lifecycle still surfaces it), the
    // mode gate of the new wait, and the acceptance case itself: one frame
    // larger than two pipe buffers to a slow reader, end to end through a
    // real forked writer so the would-block wait and the partial resumption
    // are traversed for real. Forked pins follow the house discipline used
    // across this suite (see the forked-HTTP harnesses in HttpTest):
    // bounded child, event-driven stream_select waits, bounded reap,
    // pcntl-guarded skip. The window SHAPE itself is the plan's code-review
    // item — PHP core re-chunks every userland fwrite internally (8 KiB
    // calls on a sink), so no in-process census can observe the caller's
    // slice size; the report carries a measured old-vs-new backpressure
    // bench instead of a fake-precision assertion.

    /** writeRecord is private static; reflection seam per the decodeFrame pattern. */
    private function invokeWriteRecord($stream, mixed $record): void
    {
        $ref = new \ReflectionMethod(WorkerPool::class, 'writeRecord');
        $ref->invoke(null, $stream, $record);
    }

    /** awaitWritable is private static; same seam, for the mode-gate pins. */
    private function invokeAwaitWritable($stream): bool
    {
        $ref = new \ReflectionMethod(WorkerPool::class, 'awaitWritable');
        return (bool) $ref->invoke(null, $stream);
    }

    /** @return resource a fresh full-accept sink (statics reset) */
    private function openWindowSink()
    {
        if (!in_array('s13-window-sink', stream_get_wrappers(), true)) {
            stream_wrapper_register('s13-window-sink', S13WindowSink::class);
        }
        S13WindowSink::reset();
        $handle = fopen('s13-window-sink://sink', 'wb');
        $this->assertIsResource($handle);
        return $handle;
    }

    /** A byte pattern where every 1 KiB block differs: any cursor slip shows. */
    private function buildTaggedBlob(int $bytes): string
    {
        $out = '';
        $tag = 0;
        while (strlen($out) < $bytes) {
            $out .= pack('N', $tag) . str_repeat(chr(0x41 + ($tag % 26)), 1020);
            $tag++;
        }
        return $out;
    }

    public function testWriteRecordResumesTheCursorAfterARefusalAndDeliversTheWholeFrame(): void
    {
        // The deterministic in-process cursor pin. The sink refuses exactly
        // one numbered device call (a would-block inside the first fwrite):
        // fwrite #1 comes back SHORT, and the rewrite must resume at the
        // accepted offset and finish the frame. Mutants this lands red:
        //   - cursor advanced by the requested window (bytes 24K..64K are
        //     skipped → the delivered stream is the prefix plus a tail from
        //     64K, never the frame);
        //   - cursor not advanced (the header re-arrives mid-frame);
        //   - the pre-S13 posture of quitting on the zero return (delivery
        //     stops at the refusal).
        // The window SHAPE (fixed slice, never the shrinking remainder) is
        // the plan's code-review item: core re-chunks every userland fwrite
        // internally, so no sink-side census can observe the caller's slice
        // size — the lane report carries a measured backpressure bench.
        $sink = $this->openWindowSink();
        S13WindowSink::$refuseCall = 4;

        $record = ['kind' => 's13-window', 'blob' => $this->buildTaggedBlob(200000)];
        $start  = microtime(true);
        $this->invokeWriteRecord($sink, $record);

        $payload = serialize($record);
        $framed  = pack('N', strlen($payload)) . $payload;

        $this->assertSame($framed, S13WindowSink::$delivered, 'one refused device call must cost nothing: the frame lands whole, in order, un-duplicated');
        $this->assertGreaterThanOrEqual(4, count(S13WindowSink::$requests), 'the refusal was actually met');
        $this->assertLessThan(1.0, microtime(true) - $start, 'the resume must be immediate on an accepting sink');
    }

    public function testWriteRecordStopsPromptlyOnAStreamThatTakesNothing(): void
    {
        // Blocking-mode zero posture (production's shape): the child end is
        // a BLOCKING socketpair stream, so a zero return is already the
        // kernel's final word — the write ends there without spinning. The
        // pool's EOF-without-sentinel lifecycle is what surfaces the loss;
        // a wait-loop or a throw here would change that taxonomy.
        $sink = $this->openWindowSink();
        S13WindowSink::$refuseAll = true;

        $start = microtime(true);
        $this->invokeWriteRecord($sink, ['__done' => true]);

        $this->assertSame('', S13WindowSink::$delivered, 'nothing may land on a stream that takes nothing');
        $this->assertLessThanOrEqual(2, count(S13WindowSink::$requests), 'the blocking-mode refusal must end the write after at most the first attempt — no retry loop, no stall-belt churn');
        $this->assertLessThan(1.0, microtime(true) - $start, 'a blocking-mode zero return must end the write, not spin on it');
    }

    public function testWriteRecordEndsTheWriteOnADeafPeerWithoutThrowing(): void
    {
        // Dead-peer posture (broken pipe) is unchanged by S13 — and must
        // stay unchanged: an aborted stream is surfaced by the drain loop's
        // EOF path plus S10's exit-status refusal. A rewrite that threw (or
        // waited) here would turn a cancelled/dead-child run into a
        // different error shape.
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$childEnd, $parentEnd] = $pair;
        fclose($parentEnd); // the reader is gone before the first byte

        $start = microtime(true);
        $this->invokeWriteRecord($childEnd, ['__done' => true]);
        $this->assertLessThan(1.0, microtime(true) - $start, 'the dead-peer stop must be immediate, never a wait');
        $this->assertTrue(@feof($childEnd), 'the EPIPE condition must leave the stream flagged for the surrounding lifecycle to meet');
        fclose($childEnd);
    }

    public function testAwaitWritableHonoursTheStreamsDeclaredMode(): void
    {
        // Mode gate of the new wait: only a stream that can NAME its
        // non-blocking mode is ever parked on. A blocking stream's zero
        // return is already the kernel's final word; a stream type that
        // never reports the flag (php://temp carries no 'blocked' key at
        // all) cannot be waited on honestly and must not enter a loop.
        $temp = fopen('php://temp', 'w+b');
        $this->assertIsResource($temp);
        $this->assertFalse($this->invokeAwaitWritable($temp), 'a stream with no declared mode must not enter a wait loop');
        fclose($temp);

        $sink = $this->openWindowSink();
        $this->assertFalse($this->invokeAwaitWritable($sink), 'a blocking-mode stream must not enter a wait loop');
        fclose($sink);

        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$a, $b] = $pair;
        $this->assertTrue(stream_set_blocking($b, false));
        $this->assertTrue($this->invokeAwaitWritable($b), 'an empty non-blocking socket is writable — the wait answers immediately');
        fclose($a);
        fclose($b);
    }

    public function testWriteRecordDeliversAFrameLargerThanTwoPipeBuffersToASlowReader(): void
    {
        // The acceptance case verbatim: a record that cannot fit the kernel
        // queue twice over, streamed to a reader that deliberately lags one
        // small chunk per event. The writer must meet would-block (fwrite 0)
        // and partial acceptances (fwrite short) again and again — an empty
        // queue filled by 64 KiB windows outruns any 16 KiB-per-pulse
        // reader, so both arms are traversed by necessity, not by luck —
        // and the delivered stream must be the exact frame.
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }

        $capacity = $this->measureSocketPairCapacity();
        if ($capacity >= self::PIPE_PROBE_CEILING_BYTES) {
            $this->markTestSkipped('measured socketpair capacity hit the probe ceiling — fixture would dominate memory');
        }
        $size    = $capacity * 2 + (4 << 20);
        $record  = ['kind' => 's13-slow-reader', 'blob' => $this->buildTaggedBlob($size)];
        $payload = serialize($record);

        $ctx = hash_init('sha256');
        hash_update($ctx, pack('N', strlen($payload)));
        hash_update($ctx, $payload);
        $expectedHash  = hash_final($ctx);
        $expectedBytes = 4 + strlen($payload);

        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$childEnd, $parentEnd] = $pair;
        $this->assertTrue(
            stream_set_blocking($childEnd, false),
            'the writer end must be non-blocking: the would-block/wait arm is what this pin drives'
        );

        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($childEnd);
            fclose($parentEnd);
            $this->markTestSkipped('pcntl_fork failed');
        }
        if ($pid === 0) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            fclose($parentEnd);
            $this->invokeWriteRecord($childEnd, $record);
            fclose($childEnd);
            exit(0);
        }

        // Parent: the slow reader — one bounded chunk per select pulse
        // (the pool's own drain granularity), and nothing faster.
        fclose($childEnd);
        unset($record, $payload); // the fixture is on the wire; the parent only verifies it
        $this->assertTrue(stream_set_blocking($parentEnd, false));
        $seenBytes = 0;
        $head      = '';
        $running   = hash_init('sha256');
        $deadline  = microtime(true) + 120.0;
        while (true) {
            if (microtime(true) >= $deadline) {
                posix_kill($pid, SIGKILL);
                $this->fail('the writer never finished behind its own backpressure — the slow reader saw a stalled stream');
            }
            $readSet = [$parentEnd];
            $write   = null;
            $except  = null;
            $ready   = @stream_select($readSet, $write, $except, 1, 0);
            if ($ready === false) {
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                continue; // EINTR posture, same as the pool's own drain loop
            }
            if ($ready === 0) {
                if (@feof($parentEnd)) {
                    break; // the writer closed its end; nothing more will arrive
                }
                continue;  // data may still be coming — the child is alive and writing
            }
            $chunk = fread($parentEnd, 16384);
            if ($chunk === false || $chunk === '') {
                if (@feof($parentEnd)) {
                    break;
                }
                continue;
            }
            if ($head === '') {
                $head = substr($chunk, 0, 4);
            }
            $seenBytes += strlen($chunk);
            hash_update($running, $chunk);
        }

        $status = 0;
        pcntl_waitpid($pid, $status);
        fclose($parentEnd);

        $this->assertTrue(pcntl_wifexited($status), 'the writer must exit cleanly, not die on a signal');
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame($expectedBytes, $seenBytes, 'the frame must arrive whole — every would-block resumed instead of truncating');
        $this->assertSame($expectedHash, hash_final($running), 'the frame must arrive byte-exact in order under backpressure');
        $this->assertSame(4, strlen($head), 'the length header must be the first thing on the wire');
        $this->assertSame($expectedBytes - 4, unpack('N', $head)[1], 'the header must declare exactly the payload that followed it');
    }

    /** Ceiling for the socketpair capacity probe (also the fixture guard). */
    private const PIPE_PROBE_CEILING_BYTES = 64 << 20;

    /** How many bytes an unread socketpair absorbs before fwrite would-blocks. */
    private function measureSocketPairCapacity(): int
    {
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$a, $b] = $pair;
        $this->assertTrue(stream_set_blocking($b, false));
        $fill  = str_repeat('p', 262144);
        $bytes = 0;
        while ($bytes < self::PIPE_PROBE_CEILING_BYTES) {
            $n = @fwrite($b, $fill);
            if ($n === false || $n === 0) {
                break;
            }
            $bytes += $n;
        }
        fclose($a);
        fclose($b);
        return $bytes;
    }

    // ── once-S45 (php-dup-finder-once-049): child signal disarm — kill-switch real ──
    //
    // fork() copies the parent's signal disposition wholesale. Under the
    // TUI (Program::catchInterrupts), the CLI SignalHandler, `serve`, or the
    // watch loop, the parent runs with pcntl_async_signals(true) plus
    // userland handlers for SIGTERM/SIGINT/SIGHUP whose cooperative contract
    // is "stop the PARENT's loop" — in a forked worker they run and RETURN,
    // so the pool's teardown `kill -TERM <worker-pid>` does nothing and the
    // finally waits out the whole chunk beside a child that believes it is
    // being asked politely (the finding measured 2.84 s abandon vs 0.21 s).
    // The pins arm exactly that immortal posture in the test parent and
    // require: the child branch reports the shed posture through the wire
    // itself (async OFF, SIGTERM back to SIG_DFL, an inherited SIG_IGN back
    // to SIG_DFL); a real SIGTERM on a worker pid still kills it AND the
    // pool marks the death; and the same at raw-process level, where the
    // waitpid status must be the signal death a shell renders as 143.
    // Every pin restores the parent process's own posture in a finally —
    // the disarm runs in children only; the serial fallback must never
    // touch the process that armed the handlers.

    /** Fork plus the async/get-handler probes need all of it: honest skip. */
    private function requireSignalDisarmHarness(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal_get_handler')) {
            $this->markTestSkipped('pcntl_async_signals/pcntl_signal_get_handler unavailable');
        }
    }

    /**
     * Arm the immortal posture from the finding: async delivery ON, a no-op
     * SIGTERM handler (the catchInterrupts/serve shape), and SIGHUP ignored
     * (the inherited-SIG_IGN shape the reset must also shed). Returns the
     * restore closure the caller MUST run in a finally.
     *
     * @return \Closure(): void
     */
    private function armImmortalParentPosture(): \Closure
    {
        $prevAsync = pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): void {
            // deliberate no-op: catches the kill and returns — the exact
            // handler contract that made inherited workers immortal
        });
        pcntl_signal(SIGHUP, SIG_IGN);

        return static function () use ($prevAsync): void {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGHUP, SIG_DFL);
            pcntl_async_signals($prevAsync);
        };
    }

    /** disarmInheritedSignals is private static; reflection seam per the S13 pattern. */
    private function invokeDisarmInheritedSignals(): void
    {
        $ref = new \ReflectionMethod(WorkerPool::class, 'disarmInheritedSignals');
        $ref->invoke(null);
    }

    public function testForkedChildReportsDisarmedAsyncModeAndDefaultHandlers(): void
    {
        // Acceptance leg "pcntl_async_signals off in the child branch
        // asserted via child probe output" — through the real pool wire.
        // Each child serializes its OWN posture as a scalar record: an
        // un-disarmed child reports async=true and handler/ignore
        // dispositions, failing every assertion below.
        $this->requireSignalDisarmHarness();
        $restore = $this->armImmortalParentPosture();
        try {
            $this->withCpuEnv('2', function (): void {
                $pool = new WorkerPool(workers: 2);
                $probes = iterator_to_array($pool->runStreaming(
                    range(1, 16),
                    static fn(array $batch): array => [[
                        'pid'         => getmypid(),
                        'async'       => pcntl_async_signals(),
                        // Kernel-default reading, both spellings: PHP
                        // reports an explicit SIG_DFL restore as int(0)
                        // (measured on this box) and a never-owned signal
                        // as false. The immortal shapes this step cures —
                        // a Closure (the parent's userland handler) or
                        // true (an inherited SIG_IGN) — are neither.
                        // Distilled to a plain bool: the wire stays
                        // scalar-only (socketpair law).
                        'termDefault' => in_array(pcntl_signal_get_handler(SIGTERM), [false, SIG_DFL], true),
                        'hupDefault'  => in_array(pcntl_signal_get_handler(SIGHUP), [false, SIG_DFL], true),
                    ]],
                ), false);
                $this->assertCount(2, $probes, 'both forked children probe their own posture');
                foreach ($probes as $probe) {
                    $this->assertNotSame(getmypid(), $probe['pid'], 'the probe ran in a forked child, not the serial fallback');
                    $this->assertFalse($probe['async'], 'async signal dispatch must be OFF in the child branch');
                    $this->assertTrue($probe['termDefault'], 'an inherited SIGTERM handler must be back to SIG_DFL in the child');
                    $this->assertTrue($probe['hupDefault'], 'an inherited SIGHUP SIG_IGN must be back to SIG_DFL in the child');
                }
            });
        } finally {
            $restore();
        }
    }

    public function testKillTermOnAWorkerDiesAndIsMarkedDespiteImmortalPosture(): void
    {
        // Acceptance leg "parent kill -TERM <worker-pid> → pool marks the
        // death": the target child reports its pid, then idles two seconds
        // before its survivor record could ever appear. Fixed shape: the
        // kill lands default-style within the idle, the stream ends
        // WITHOUT its sentinel, and the exit-status contract throws naming
        // the signal. Pre-S45 shape: the child caught its own SIGTERM,
        // returned, streamed 'late', and the run completed clean.
        $this->requireSignalDisarmHarness();
        $restore = $this->armImmortalParentPosture();
        try {
            $this->withCpuEnv('2', function (): void {
                $pool   = new WorkerPool(workers: 2);
                $start  = microtime(true);
                $seen   = [];
                $caught = null;
                try {
                    foreach ($pool->runStreaming(
                        range(1, 16),
                        static function (array $batch): \Generator {
                            if (in_array(1, $batch, true)) {
                                yield ['kind' => 'target', 'pid' => getmypid()];
                                $deadline = microtime(true) + 2.0;
                                while (microtime(true) < $deadline) {
                                    usleep(20000);
                                }
                                yield ['kind' => 'late'];
                                return;
                            }
                            foreach ($batch as $x) {
                                yield ['kind' => 'ok', 'x' => $x];
                            }
                        },
                    ) as $record) {
                        $seen[] = $record;
                        if ($record['kind'] === 'target') {
                            posix_kill($record['pid'], SIGTERM);
                        }
                    }
                } catch (\RuntimeException $e) {
                    $caught = $e;
                }
                $this->assertNotNull(
                    $caught,
                    'a SIGTERM-struck worker must surface as a marked death, not a survivor stream'
                );
                $this->assertMatchesRegularExpression(
                    '/Worker \d+ was killed by signal 15 \(SIGTERM\)/',
                    $caught->getMessage(),
                    'the pool names the kill it delivered — the kill switch is real under inherited handlers'
                );
                $this->assertNotContains(['kind' => 'late'], $seen, 'the killed worker never reached its survivor record');
                $this->assertLessThan(10.0, microtime(true) - $start, 'the kill is honored promptly — no wait-out-the-chunk hang');
                $this->assertPoolLeftNoStrayChildren();
            });
        } finally {
            $restore();
        }
    }

    public function testDisarmedChildDiesByRawSigtermUnderImmortalParent(): void
    {
        // Acceptance leg "child dies 143" at the process layer: a direct
        // fork under the armed immortal posture, the child's first act
        // being the very call the pool's child branch makes (reflection
        // seam), and the raw waitpid status must read as a signal death by
        // SIGTERM — 128 + 15 = 143, the code a shell reports — never as a
        // survived exit.
        $this->requireSignalDisarmHarness();
        $restore = $this->armImmortalParentPosture();
        try {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->markTestSkipped('pcntl_fork failed');
            }
            if ($pid === 0) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                $this->invokeDisarmInheritedSignals();
                // Reach the deadline only if the disarm failed: an
                // immortal posture swallows the kill and walks out exit(7).
                $deadline = microtime(true) + 2.0;
                while (microtime(true) < $deadline) {
                    usleep(20000);
                }
                exit(7);
            }
            usleep(150000); // let the child arm its own default dispositions
            posix_kill($pid, SIGTERM);
            $status    = 0;
            $collected = 0;
            $deadline  = microtime(true) + 6.0;
            while ($collected !== $pid) {
                if (microtime(true) >= $deadline) {
                    $this->fail("worker pid $pid never became collectable after SIGTERM");
                }
                $collected = pcntl_waitpid($pid, $status, WNOHANG);
                if ($collected === -1) {
                    $this->fail("waitpid for pid $pid failed before the child was reaped");
                }
                if ($collected === 0) {
                    usleep(20000);
                }
            }
            $outcome = pcntl_wifexited($status)
                ? 'survived the kill and exited ' . pcntl_wexitstatus($status)
                : 'terminated by signal ' . pcntl_wtermsig($status);
            $this->assertTrue(pcntl_wifsignaled($status), "the disarmed child must die of SIGTERM (143-shape), got: $outcome");
            $this->assertSame(SIGTERM, pcntl_wtermsig($status), 'raw terminate signal is SIGTERM — 128 + 15 is the 143 a shell reports');
        } finally {
            $restore();
        }
    }
}

/**
 * shell-S13 harness: a userland write sink that can simulate a transient
 * would-block (return 0 on one numbered stream_write call) or a hard
 * refusal (every call), accepting everything else in full. PHP core
 * re-chunks each fwrite into its own device calls (8 KiB), so a call-index
 * refusal drives the exact sequence writeRecord must survive: a SHORT
 * acceptance (>0 — the cursor must advance by what the device took)
 * followed by a zero return on a stream in BLOCKING mode (an error
 * posture). Wrappers cannot be armed non-blocking (stream_set_blocking has
 * no effect), which keeps the blocking arms landing on awaitWritable()'s
 * mode gate instead of its wait loop.
 */
class S13WindowSink
{
    /** @var mixed */
    public $context;

    /** @var list<int> length of every stream_write call, in order */
    public static array $requests = [];

    /** @var string everything the sink accepted, concatenated (the wire) */
    public static string $delivered = '';

    /** 1-based call index that must answer 0 once (a would-block); 0 disables */
    public static int $refuseCall = 0;

    /** when true every call answers 0 (a stream that will not take data at all) */
    public static bool $refuseAll = false;

    public static function reset(): void
    {
        self::$requests    = [];
        self::$delivered   = '';
        self::$refuseCall  = 0;
        self::$refuseAll   = false;
    }

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        return true;
    }

    public function stream_write($data)
    {
        self::$requests[] = strlen($data);
        if (self::$refuseAll) {
            return 0;
        }
        if (self::$refuseCall !== 0 && count(self::$requests) === self::$refuseCall) {
            return 0;
        }
        self::$delivered .= $data;
        return strlen($data);
    }

    public function stream_eof()
    {
        return false;
    }

    public function stream_close()
    {
    }
}
