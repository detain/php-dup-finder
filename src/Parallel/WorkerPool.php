<?php
declare(strict_types=1);

namespace Phpdup\Parallel;

use Phpdup\Persistence\SerializedClassAllowList;

/**
 * pcntl_fork-based worker pool for embarrassingly parallel batch work.
 *
 * Single mode:
 *
 *   - {@see runStreaming()} — yield-as-results-arrive. Each child streams
 *     records through a per-process socketpair as soon as they're
 *     produced; the parent {@see stream_select()}s across all children and
 *     the returned {@see \Generator} yields each record live. Used by the
 *     cooperative pipeline so the TUI can repaint mid-stage.
 *
 * If `pcntl_fork` is unavailable (Windows, restricted PHP build) or
 * `workers` ≤ 1, the pool runs the work serially in the parent.
 *
 * Children inherit the parent's loaded autoloader, so the closure can
 * use any class without re-bootstrapping. Closures must be
 * Serializable-compatible: no references to non-serializable objects
 * outside the captured `use` list. Plain functions (Closure::bind to
 * static or top-level) are safest.
 */
final class WorkerPool
{
    /** Length-prefix size for the streaming framing protocol (4 bytes, big-endian uint32). */
    private const FRAME_HEADER = 4;

    /**
     * shell-038 — ceiling multiplier for the effective concurrent-worker
     * count: the pool never forks more than `4 × detected CPUs` children in
     * one run, no matter how large the requested value is. The request can
     * arrive through several admission layers (CLI flag, config file,
     * programmatic Config/WorkerPool construction, serve workers), so the
     * clamp lives HERE — the one choke point every fork path passes
     * through — and ConfigLoader only notes an above-ceiling request
     * (shell-S10 lane ruling: clamp-with-note, not an exit-2 rejection).
     */
    public const WORKERS_CEILING_MULTIPLIER = 4;

    /**
     * once-S77 (once-093): the DECLARED fallback CPU count for platforms
     * the probe chain in {@see detectCpuCount()} cannot see (a macOS or
     * FreeBSD host without a cgroup quota). The old code hardcoded the same
     * 4, but reached it silently through a Linux-only shell pipeline; now
     * it is an intentional, documented floor after every portable probe.
     */
    public const CPU_FALLBACK_COUNT = 4;

    /**
     * shell-S10 fix (review L-2): how long the exit-status verification pass
     * waits for a child whose stream has already ended to be collectable on
     * its own before escalating to SIGTERM, then (after the term grace) to
     * SIGKILL. The windows are bounded BY DESIGN: the pre-fix single
     * blocking pcntl_waitpid could stall the whole analyze forever on a
     * SIGSTOPped or shutdown-stalled child — a wedged child must surface as
     * a descriptive error, never as an infinite hang.
     */
    private const VERIFY_COLLECT_GRACE_SECONDS = 5.0;
    private const VERIFY_TERM_GRACE_SECONDS    = 2.0;
    private const VERIFY_KILL_GRACE_SECONDS    = 2.0;

    /** Poll cadence inside the verification grace windows. */
    private const VERIFY_POLL_MICROSECONDS = 20000;

    /**
     * Test seam shrinking the three verification graces by a factor
     * (1.0 = production timing). Production never sets it; tests MUST
     * restore null in a finally — same discipline as
     * {@see setSocketPairFactory()}.
     */
    private static ?float $verifyGraceScale = null;

    /**
     * Test seam for the socketpair creation inside the fork loop
     * (shell-028): lets a test force a mid-loop failure after k children
     * were already spawned and prove the partial set is reaped. Production
     * code never sets it.
     *
     * @var (\Closure(): (false|array{0: resource, 1: resource}))|null
     */
    private static ?\Closure $socketPairFactory = null;

    /**
     * Probe-read seam for the CPU-detection file probes (once-S77):
     * `path → contents|null`. Production never installs it (reads the real
     * /sys and /proc files through the PHP file API); unit tests install
     * fixture readers so "container with a cpu.max quota" scenarios are
     * reproducible on any host. Same restore-in-finally discipline as
     * {@see setSocketPairFactory()}.
     *
     * @var (\Closure(string): (string|null))|null
     */
    private static ?\Closure $sysFileReader = null;

    public function __construct(
        public readonly int $workers = 0,
    ) {
    }

    public static function isAvailable(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('posix_kill');
    }

    /**
     * Hard ceiling for concurrent children in one run: 4 × the detected
     * CPU count (PHPDUP_WORKERS SEEDS the detection through
     * {@see detectCpuCount()} whenever the count is automatic, and
     * therefore moves the ceiling too — but an explicit `--workers N`
     * request is independent of it, once-110 seed-not-override).
     */
    public static function workersCeiling(): int
    {
        return self::WORKERS_CEILING_MULTIPLIER * max(1, self::detectCpuCount());
    }

    /**
     * Clamp a requested worker count into [1, {@see workersCeiling()}].
     * Applied before chunking so an oversized request yields fewer, bigger
     * chunks (chunk/child count == ceiling) instead of a fork storm.
     */
    public static function clampWorkers(int $requested): int
    {
        return min(max(1, $requested), self::workersCeiling());
    }

    /**
     * Install the socketpair test seam (see $socketPairFactory); pass null
     * to restore the real stream_socket_pair(). Tests MUST restore it in a
     * finally block: the pool is shared across the whole test process.
     *
     * @param (\Closure(): (false|array{0: resource, 1: resource}))|null $factory
     */
    public static function setSocketPairFactory(?\Closure $factory): void
    {
        self::$socketPairFactory = $factory;
    }

    /**
     * Install the verification-grace scale (see $verifyGraceScale); pass
     * null to restore production timing. Tests MUST restore it in a finally.
     */
    public static function setVerifyGraceScale(?float $scale): void
    {
        self::$verifyGraceScale = $scale;
    }

    /** Production grace scaled through the test seam. */
    private static function verifyGrace(float $production): float
    {
        return self::$verifyGraceScale === null ? $production : $production * self::$verifyGraceScale;
    }

    /** @return false|array{0: resource, 1: resource} child end first */
    private static function openWorkerPair()
    {
        $factory = self::$socketPairFactory;
        if ($factory !== null) {
            return $factory();
        }
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        return $pair === false ? false : $pair;
    }

    /**
     * Install the probe-read seam (once-S77); pass null to restore real
     * file reads. Tests MUST restore it in a finally block: the pool is
     * shared across the whole test process. While a seam is installed,
     * {@see detectCpuCount()} probes fresh on every call and never writes
     * the production memoization cache, so fixture results cannot leak.
     *
     * @param (\Closure(string): (string|null))|null $reader
     */
    public static function setSysFileReader(?\Closure $reader): void
    {
        self::$sysFileReader = $reader;
    }

    /**
     * Portable CPU-count detection — no shell (once-S77 retires
     * `shell_exec('nproc 2>/dev/null')`, which was Linux-only advice,
     * ignored cgroup quotas (containers counted the whole host), and on
     * Windows reached cmd with a `2>/dev/null` redirect cmd does not honor).
     *
     * Candidate order, first positive wins:
     *
     *   1. `PHPDUP_WORKERS` — an explicit SEED for the detection: it
     *      short-circuits every probe and moves the fork ceiling
     *      ({@see workersCeiling()}), but it never overrides a
     *      `--workers N` request with N ≥ 1 (once-110: seed-not-override,
     *      pinned by WorkerPoolTest and documented in docs/CLI.md).
     *   2. cgroup CPU quota — v2 `cpu.max`, then the v1 cpu-controller
     *      cfs_quota/cfs_period pair: a quota-limited container counts its
     *      share, not the host topology.
     *   3. `/proc/cpuinfo` processor entries (Linux hosts).
     *   4. `NUMBER_OF_PROCESSORS` — the portable Windows equivalent, read
     *      through plain getenv(): no shell, no redirect.
     *   5. {@see CPU_FALLBACK_COUNT} — the DECLARED default for platforms
     *      nothing above can see (macOS/FreeBSD hosts), replacing the old
     *      silent `4` with a documented constant.
     */
    public static function detectCpuCount(): int
    {
        static $cache = null;

        // PHPDUP_WORKERS always short-circuits: env wins over any cached value.
        $env = (int)getenv('PHPDUP_WORKERS');
        if ($env > 0) {
            $cache = $env;
            return $env;
        }

        // A seam-installed run is a test run: probe fresh, never memoize.
        if (self::$sysFileReader !== null) {
            return max(1, self::probeCpuCount());
        }

        if ($cache !== null) {
            return $cache;
        }
        $cache = max(1, self::probeCpuCount());
        return $cache;
    }

    /** The probe chain of {@see detectCpuCount()} after the env seed. */
    private static function probeCpuCount(): int
    {
        $candidates = [
            self::cgroupQuotaCpuCount(),
            self::cpuinfoCount(),
            self::windowsProcessorCount(),
            self::CPU_FALLBACK_COUNT,
        ];
        foreach ($candidates as $n) {
            if ($n > 0) {
                return $n;
            }
        }
        return 1;
    }

    /**
     * cgroup CPU quota — the container truth. 0 means "no quota visible,
     * keep probing". Quotas that are not whole multiples of the period
     * round UP: 1.8 CPUs still gets 2 children, mirroring the kernel's own
     * slice delivery over a wall-clock window.
     */
    private static function cgroupQuotaCpuCount(): int
    {
        $v2 = self::parseCgroupV2Max(self::readProbeFile('/sys/fs/cgroup/cpu.max'));
        if ($v2 > 0) {
            return $v2;
        }
        return self::parseCgroupV1Quota(
            self::readProbeFile('/sys/fs/cgroup/cpu/cpu.cfs_quota_us'),
            self::readProbeFile('/sys/fs/cgroup/cpu/cpu.cfs_period_us'),
        );
    }

    /**
     * Parse a cgroup v2 `cpu.max` body: "QUOTA PERIOD" (microseconds) or
     * "max PERIOD" when unlimited. Non-positive or malformed bodies yield
     * 0 (= "no quota seen"), never an error.
     */
    public static function parseCgroupV2Max(?string $contents): int
    {
        if ($contents === null) {
            return 0;
        }
        $fields = preg_split('/\s+/', trim($contents), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($fields) || count($fields) < 2) {
            return 0;
        }
        [$quotaRaw, $periodRaw] = $fields;
        // "max 100000" is the unlimited spelling; anything non-digit in the
        // two slots is a malformed body — both keep probing.
        if (!ctype_digit($quotaRaw) || !ctype_digit($periodRaw)) {
            return 0;
        }
        $quota  = (int)$quotaRaw;
        $period = (int)$periodRaw;
        if ($quota <= 0 || $period <= 0) {
            return 0;
        }
        return (int)ceil($quota / $period);
    }

    /**
     * Parse the cgroup v1 cpu-controller pair (cfs_quota_us /
     * cfs_period_us). A quota of -1 (unlimited), a missing side, or any
     * non-positive value yields 0 (= "no quota seen").
     */
    public static function parseCgroupV1Quota(?string $quota, ?string $period): int
    {
        if ($quota === null || $period === null) {
            return 0;
        }
        $q = (int)trim($quota);
        $p = (int)trim($period);
        if ($q <= 0 || $p <= 0) {
            return 0;
        }
        return (int)ceil($q / $p);
    }

    /** Linux host topology: one "processor\t:" entry per logical CPU. */
    private static function cpuinfoCount(): int
    {
        return self::parseCpuinfo(self::readProbeFile('/proc/cpuinfo'));
    }

    /** Parse a /proc/cpuinfo body (null when the file is not readable). */
    public static function parseCpuinfo(?string $contents): int
    {
        if ($contents === null) {
            return 0;
        }
        return substr_count($contents, "processor\t:");
    }

    /**
     * Windows: the OS exports NUMBER_OF_PROCESSORS to every process — the
     * portable equivalent of `nproc` there, read through plain getenv().
     * On other platforms the variable is simply unset, so the probe falls
     * through (0) instead of shelling out.
     */
    private static function windowsProcessorCount(): int
    {
        $raw = getenv('NUMBER_OF_PROCESSORS');
        if (!is_string($raw)) {
            return 0;
        }
        $n = (int)trim($raw);
        return $n > 0 ? $n : 0;
    }

    /** Seam-first read of a probe file; null when absent or unreadable. */
    private static function readProbeFile(string $path): ?string
    {
        $reader = self::$sysFileReader;
        if ($reader !== null) {
            return $reader($path);
        }
        if (!is_readable($path)) {
            return null;
        }
        $contents = @file_get_contents($path);
        return $contents === false ? null : $contents;
    }

    /**
     * Streaming variant. The task closure may return either an
     * array or a {@see \Generator} of records; this method yields each record
     * to the caller as soon as it arrives from a child process, instead of
     * waiting for the whole pool to finish.
     *
     * Falls back to serial in-process iteration when {@see isAvailable()}
     * returns false, when workers ≤ 1, or for trivially small inputs (<8
     * items).
     *
     * @template TItem
     * @template TResult
     * @param list<TItem> $items
     * @param \Closure(list<TItem>): iterable<TResult> $task
     * @return \Generator<int, TResult>
     *
     * @note When the generator is abandoned (e.g., on TUI/pipeline cancel with ^C),
     * the `finally` block is triggered via PHP Generator GC: children are sent
     * SIGTERM via posix_kill() before being reaped with pcntl_waitpid(), ensuring
     * no orphaned workers linger on stream_select(). A deliberate abandon is
     * silent by design — the exit-status verification below runs only when the
     * drain loop completed on its own, so cancelling never surfaces as a throw.
     *
     * @throws \RuntimeException if a child dies mid-chunk, exits nonzero,
     *         exits without its __done sentinel, reports an __error frame,
     *         is a wedged post-sentinel child killed by the bounded
     *         verification escalation, or the fork set cannot be completed
     *         (shell-005, shell-028, shell-S10).
     */
    public function runStreaming(array $items, \Closure $task): \Generator
    {
        if (!$items) return;

        $workers = max(1, $this->workers > 0 ? $this->workers : self::detectCpuCount());
        // shell-038: bound the fork set BEFORE chunking — an oversized
        // request yields fewer, bigger chunks instead of one child per file.
        $workers = self::clampWorkers($workers);
        if ($workers === 1 || !self::isAvailable() || count($items) < 8) {
            yield from $task($items);
            return;
        }

        $chunks   = self::chunkInto($items, $workers);
        $pipes    = [];   // parent socket per chunk index
        $pids     = [];   // child pid per chunk index; entries vanish as reaped
        $buffers  = [];
        $sawDone  = [];   // chunk idx → child's __done sentinel arrived
        $gcCounter = 0;

        // Decode with the same explicit class allow-list the disk caches use
        // (IndexStore / ClusterCache via SerializedClassAllowList): worker
        // rows legitimately embed Block / Hole / LineRange / PhpParser node
        // graphs, and 'allowed_classes' => false turned every such object
        // arriving here into __PHP_Incomplete_Class, corrupting downstream
        // consumers (BUG-B: `analyze src` died at PreprocessStage id-assign).
        // Hoisted per run: parserClasses() is memoized internally, but
        // blockCacheClasses() re-runs the array merge on each call and this
        // is the hot decode path.
        $allowList = SerializedClassAllowList::blockCacheClasses();

        // shell-028: the try/finally that owns pipe-closing and child-reaping
        // opens BEFORE the fork loop. A stream_socket_pair() or pcntl_fork()
        // failure mid-loop then unwinds through the same cleanup: every
        // already-spawned child is SIGTERMed and reaped, every parent-side fd
        // is closed, and the generator-abandon path covers partial sets too.
        try {
            foreach ($chunks as $idx => $chunk) {
                $pair = self::openWorkerPair();
                if ($pair === false) {
                    throw new \RuntimeException(sprintf(
                        'stream_socket_pair failed before worker %d of %d could start (%d already spawned)',
                        $idx + 1,
                        count($chunks),
                        count($pids),
                    ));
                }
                [$childEnd, $parentEnd] = $pair;

                $pid = pcntl_fork();
                if ($pid === -1) {
                    // This iteration's pair is registered nowhere yet, so the
                    // finally cannot see it — close both ends here or its two
                    // fds litter the parent on every fork shortfall.
                    @fclose($childEnd);
                    @fclose($parentEnd);
                    throw new \RuntimeException(sprintf(
                        'pcntl_fork failed before worker %d of %d could start (%d already spawned)',
                        $idx + 1,
                        count($chunks),
                        count($pids),
                    ));
                }
                if ($pid === 0) {
                    // child
                    @fclose($parentEnd);
                    try {
                        foreach ($task($chunk) as $record) {
                            self::writeRecord($childEnd, $record);
                        }
                        self::writeRecord($childEnd, ['__done' => true]);
                        @fclose($childEnd);
                        exit(0);
                    } catch (\Throwable $e) {
                        @self::writeRecord($childEnd, ['__error' => $e->getMessage() . "\n" . $e->getTraceAsString()]);
                        @fclose($childEnd);
                        exit(1);
                    }
                }

                // parent
                @fclose($childEnd);
                stream_set_blocking($parentEnd, false);
                $pipes[$idx]   = $parentEnd;
                $pids[$idx]    = $pid;
                $buffers[$idx] = '';
                $sawDone[$idx] = false;
            }

            while ($pipes !== []) {
                $read   = $pipes;
                $write  = null;
                $except = null;
                $ready  = @stream_select($read, $write, $except, 1, 0);
                if ($ready === false) {
                    // EINTR can show up here when a signal arrives mid-select; just retry.
                    if (function_exists('pcntl_signal_dispatch')) {
                        pcntl_signal_dispatch();
                    }
                    continue;
                }
                if ($ready === 0) {
                    continue;
                }

                foreach (array_keys($read) as $idx) {
                    $sock = $pipes[$idx];
                    $chunk = @fread($sock, 65536);
                    if ($chunk === false || ($chunk === '' && @feof($sock))) {
                        @fclose($sock);
                        unset($pipes[$idx]);
                        // shell-005: EOF without the child's __done sentinel is
                        // never a normal close — the child died or exited short
                        // of its contract. If it is already collectable, fail
                        // now instead of idling until the slowest sibling
                        // drains; otherwise the post-drain verification pass
                        // below collects and verifies it (a child is collectable
                        // at latest once it has exited, and it exits right after
                        // the socket can no longer carry more data).
                        if (!$sawDone[$idx]) {
                            $status   = 0;
                            $deadPid  = $pids[$idx];
                            if (@pcntl_waitpid($deadPid, $status, WNOHANG) === $deadPid) {
                                unset($pids[$idx]);
                                self::assertWorkerStatus($idx, $status, false, $buffers[$idx] ?? '');
                            }
                        }
                        continue;
                    }
                    if ($chunk === '') continue;
                    $buffers[$idx] .= $chunk;

                    $closeAfter = false;
                    while (strlen($buffers[$idx]) >= self::FRAME_HEADER) {
                        $lenInfo = unpack('N', substr($buffers[$idx], 0, self::FRAME_HEADER));
                        if ($lenInfo === false) break;
                        $len = $lenInfo[1];
                        if (strlen($buffers[$idx]) < self::FRAME_HEADER + $len) break;
                        $payload = substr($buffers[$idx], self::FRAME_HEADER, $len);
                        $buffers[$idx] = substr($buffers[$idx], self::FRAME_HEADER + $len);

                        $record = self::decodeFrame($idx, $payload, $allowList);
                        if (is_array($record) && isset($record['__error'])) {
                            throw new \RuntimeException("Worker $idx failed: " . $record['__error']);
                        }
                        if (is_array($record) && isset($record['__done'])) {
                            // shell-005: the sentinel is the ONLY thing that
                            // vouches for a complete chunk; remember it saw one.
                            $sawDone[$idx] = true;
                            $closeAfter = true;
                            break;
                        }
                        yield $record;
                    }
                    if ($closeAfter) {
                        @fclose($pipes[$idx]);
                        unset($pipes[$idx]);
                    }
                }
                // Help PHP's cyclic GC collect any objects created during
                // deserialize/unserialize round-trips before the next select.
                // Collect every 10 iterations to reduce overhead during high-volume streaming.
                if (++$gcCounter >= 10) {
                    $gcCounter = 0;
                    gc_collect_cycles();
                }
            }

            // shell-005: every worker stream is closed. Verify each still-
            // uncollected child's terminal status against the stream contract
            // BEFORE the run may count as success: the only accepted ending
            // is exit(0) with the __done sentinel observed. Signal deaths,
            // nonzero exits and sentinel-less clean exits throw — a partially
            // filled chunk is never silently dropped again, and a child that
            // raced past the fast-fail's WNOHANG window is caught here.
            // shell-S10 fix (review L-2): collection is bounded and escalates
            // (WNOHANG grace → SIGTERM → SIGKILL) instead of an unbounded
            // blocking wait, so a SIGSTOPped or shutdown-stalled child turns
            // into a descriptive throw, never a hung analyze.
            foreach ($pids as $idx => $childPid) {
                // Dealt with whatever the outcome — reaped, vanished, or
                // escalated past the graces (the child has then taken a
                // terminal signal): the finally must never wait on it again.
                unset($pids[$idx]);
                $status    = 0;
                $collected = self::collectVerificationChild($idx, $childPid, $status);
                if ($collected !== $childPid) {
                    // ECHILD/-1 without status: the child vanished from
                    // under us. Without the sentinel that is a failed chunk;
                    // with it, the stream itself already proved completion.
                    if (!$sawDone[$idx]) {
                        throw new \RuntimeException(
                            "Worker $idx vanished before its exit status could be inspected and never wrote "
                            . 'its __done sentinel. Refusing to report a partial result stream as success.'
                        );
                    }
                    continue;
                }
                self::assertWorkerStatus($idx, $status, (bool)($sawDone[$idx] ?? false), (string)($buffers[$idx] ?? ''));
            }
        } finally {
            foreach ($pipes as $sock) @fclose($sock);
            $status = 0;
            foreach ($pids as $pid) {
                // Only children NOT already collected by the drain loop or
                // the verification pass land here — the throw/cancel paths:
                // SIGTERM the stragglers, then a blocking wait so the finally
                // never returns a littered process table.
                $waitResult = @pcntl_waitpid($pid, $status, WNOHANG);
                if ($waitResult === 0) {
                    @posix_kill($pid, SIGTERM);
                    @pcntl_waitpid($pid, $status);
                }
            }
        }
    }

    /**
     * Bounded re-collection of one verification-pass child (shell-S10 fix,
     * review L-2). Mirrors the finally leg's SIGTERM posture and hardens it:
     * poll WNOHANG through the collect grace, then SIGTERM + its grace, then
     * SIGKILL + its grace. A child that somehow survives even SIGKILL without
     * ever becoming collectable raises a descriptive RuntimeException rather
     * than blocking the run forever (the kernel delivers SIGKILL through a
     * stop, so in practice this arm is a safety net, not an expected path).
     *
     * @return int the child's pid once reaped (inspect $status), or -1 when
     *              it vanished from under the pool before a window closed
     * @throws \RuntimeException when no window reaped it and it never vanished
     */
    private static function collectVerificationChild(int $idx, int $childPid, int &$status): int
    {
        $graces = [
            self::verifyGrace(self::VERIFY_COLLECT_GRACE_SECONDS),
            self::verifyGrace(self::VERIFY_TERM_GRACE_SECONDS),
            self::verifyGrace(self::VERIFY_KILL_GRACE_SECONDS),
        ];
        $signals = [null, SIGTERM, SIGKILL];
        foreach ($graces as $leg => $grace) {
            if ($signals[$leg] !== null) {
                @posix_kill($childPid, $signals[$leg]);
            }
            $collected = self::pollVerificationChild($childPid, $status, $grace);
            if ($collected === $childPid || $collected === -1) {
                return $collected;
            }
        }
        throw new \RuntimeException(sprintf(
            'Worker %d (pid %d) was still not collectable %.1f s after SIGTERM and %.1f s after SIGKILL '
            . '(on top of a %.1f s collect grace). Refusing to block the run on a wedged child.',
            $idx,
            $childPid,
            $graces[1],
            $graces[2],
            $graces[0],
        ));
    }

    /**
     * WNOHANG-poll one child until it is reaped, vanishes, or the grace runs
     * out. EINTR from waitpid only means a signal interrupted the call — the
     * poll continues inside the window; any other -1 (ECHILD) is "vanished".
     *
     * @return int the child's pid (reaped), 0 (grace expired, still
     *              uncollectable), or -1 (vanished from under the pool)
     */
    private static function pollVerificationChild(int $childPid, int &$status, float $grace): int
    {
        $deadline = microtime(true) + $grace;
        while (true) {
            $collected = @pcntl_waitpid($childPid, $status, WNOHANG);
            if ($collected === $childPid) {
                return $childPid;
            }
            if ($collected === -1) {
                if (function_exists('pcntl_get_last_error') && pcntl_get_last_error() === PCNTL_EINTR) {
                    if (microtime(true) >= $deadline) {
                        return 0;
                    }
                    usleep(self::VERIFY_POLL_MICROSECONDS);
                    continue;
                }
                return -1;
            }
            if (microtime(true) >= $deadline) {
                return 0;
            }
            usleep(self::VERIFY_POLL_MICROSECONDS);
        }
    }

    /**
     * The stream contract's single verdict for one reaped child: exit(0)
     * WITH the __done sentinel observed is the only success. Signal deaths,
     * nonzero exits and sentinel-less clean exits all throw, each with the
     * strongest diagnosis the wire provided (shell-005).
     *
     * An __error frame is NOT diagnosed here — decodeFrame's caller throws
     * the moment such a frame parses, carrying the child's message verbatim
     * ("Worker $idx failed: <message+trace>"), which is the "throw with the
     * child's message frame when present" posture. This verifier owns the
     * cases where no frame ever arrived.
     *
     * @param string $residue undrained bytes left in that index's buffer
     *                        (a half-written frame from the death moment).
     */
    private static function assertWorkerStatus(int $idx, int $status, bool $sawDone, string $residue): void
    {
        if (pcntl_wifsignaled($status)) {
            $signal = pcntl_wtermsig($status);
            if ($sawDone) {
                // shell-S10 fix (review L-2): a child struck down AFTER its
                // stream completed — the wedged-then-escalated shape the
                // bounded verification collect produces — must not be
                // described as a mid-chunk loss. The sentinel arrived; a
                // clean exit never did; the run still refuses success.
                throw new \RuntimeException(sprintf(
                    'Worker %d was killed by signal %d (%s) after its __done sentinel had arrived; it never '
                    . 'completed a clean exit. Refusing to report success on a signal-killed worker.',
                    $idx,
                    $signal,
                    self::signalName($signal),
                ));
            }
            throw new \RuntimeException(sprintf(
                'Worker %d was killed by signal %d (%s) mid-chunk%s; its stream never reached the '
                . '__done sentinel. Refusing to report a partial result stream as success.',
                $idx,
                $signal,
                self::signalName($signal),
                $residue !== '' ? sprintf(' (%d bytes of a half-written frame were still in the pipe)', strlen($residue)) : '',
            ));
        }
        $exit = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
        if ($exit !== 0) {
            throw new \RuntimeException(sprintf(
                'Worker %d exited with status %d mid-chunk without completing its sentinel. '
                . 'Refusing to report a partial result stream as success.',
                $idx,
                $exit,
            ));
        }
        if (!$sawDone) {
            throw new \RuntimeException(sprintf(
                'Worker %d exited cleanly without its __done sentinel%s. The chunk was dropped '
                . 'without completing; refusing to report a partial result stream as success.',
                $idx,
                $residue !== '' ? sprintf(' (%d bytes of a half-written frame were still in the pipe)', strlen($residue)) : '',
            ));
        }
    }

    /** Best-effort name for the worker-killing signals a bounded answer helps with. */
    private static function signalName(int $signal): string
    {
        return match ($signal) {
            SIGKILL => 'SIGKILL',
            SIGSEGV => 'SIGSEGV',
            SIGABRT => 'SIGABRT',
            SIGBUS  => 'SIGBUS',
            SIGFPE  => 'SIGFPE',
            SIGPIPE => 'SIGPIPE',
            SIGTERM => 'SIGTERM',
            SIGINT  => 'SIGINT',
            default => 'unnamed',
        };
    }

    /**
     * Decode one framed worker record, failing loud on anything the stream
     * contract forbids, and return the decoded value otherwise.
     *
     * Two reject classes, both previously silent corruption:
     *  - @unserialize() returning false on a non-decodable payload. A valid
     *    record can never serialize to an invalid payload (writeRecord owns
     *    the encoder), so false here means a broken/truncated frame — the
     *    old code yielded that false straight into the results stream.
     *  - an object whose class is missing from the allow-list arriving as
     *    __PHP_Incomplete_Class (a new wire type whose author forgot to
     *    extend the list).
     *
     * Sentinel precedence is unaffected: __error/__done frames are plain
     * arrays of string/bool (the child writes only those shapes), so the
     * malformed and blocked-class guards can never fire on a well-formed
     * control frame before the caller inspects them.
     *
     * @param list<class-string> $allowList
     */
    private static function decodeFrame(int $idx, string $payload, array $allowList): mixed
    {
        $record = @unserialize($payload, ['allowed_classes' => $allowList]);
        if ($record === false) {
            $preview = addcslashes(substr($payload, 0, 32), "\0..\37\177..\377");
            throw new \RuntimeException(
                "Worker $idx sent a frame that failed to decode: unserialize() returned false for " .
                strlen($payload) . " bytes (payload starts: \"$preview\"). " .
                'Refusing to continue with corrupt data.'
            );
        }
        // Fail loud instead of yielding silently-corrupt data: an incomplete
        // class here means the worker channel now ships a class that is
        // missing from the allow-list (see SerializedClassAllowList).
        $blocked = self::findBlockedClassName($record);
        if ($blocked !== null) {
            throw new \RuntimeException(
                "Worker $idx returned an object of class '$blocked', which is missing from the " .
                "unserialize allow-list (SerializedClassAllowList::blockCacheClasses()) — " .
                'extend the allow-list for the new wire class. Refusing to continue with corrupt data.'
            );
        }
        return $record;
    }

    /**
     * Recursively scan a decoded record for blocked classes.
     *
     * unserialize() converts every instance whose class is absent from the
     * allow-list into __PHP_Incomplete_Class; left unchecked those poison the
     * pipeline downstream (the property-access / substr() crash class this
     * guard retires). The scan walks array values and object properties —
     * every class legitimately shipped over the worker wire (Block, LineRange,
     * Hole, Cluster and all PhpParser nodes) keeps its state in public
     * properties, which get_object_vars() sees from this scope. Node
     * PROTECTED $attributes are deliberately not traversed — and they are
     * NOT scalar-only as once claimed here: every wire Block parsed from
     * commented source carries an object-valued 'comments' attribute
     * (array<PhpParser\Comment|Comment\Doc>, attached by the parser's own
     * CommentAnnotatingVisitor at the end of every parse()), alongside
     * scalar position fields (line/token/file offsets, rawValue, kind)
     * and the string ATTR_OP markers of DbOpCanonicalizer/TrinityCollapser.
     * The skip is beneficial, not lucky: every class that legitimately
     * appears inside an attribute is a PhpParser class already inside
     * blockCacheClasses(), so it can never arrive as __PHP_Incomplete_Class,
     * while the guard's real trigger — a foreign off-list class leaking over
     * the wire — keeps its state in the public properties this scan sees.
     * AstParser runs no NameResolver. Standing rule for wire-graph growth:
     * never place an object the allow-list does not cover into a node
     * attribute.
     *
     * Object ids are visited once: DAG-shaped payloads (the same node shared
     * by many holes) cost a single pass, and a pathological cycle cannot hang
     * the scan.
     *
     * @return string|null the blocked instance's original class name, or null
     *                     when the record decodes clean.
     */
    private static function findBlockedClassName(mixed $record): ?string
    {
        /** @var array<int, true> $seen */
        $seen = [];
        return self::scanForBlockedClass($record, $seen);
    }

    /** @param array<int, true> $seen spl_object_id() set of visited objects */
    private static function scanForBlockedClass(mixed $value, array &$seen): ?string
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $blocked = self::scanForBlockedClass($item, $seen);
                if ($blocked !== null) {
                    return $blocked;
                }
            }
            return null;
        }
        if (!is_object($value)) {
            return null;
        }
        $oid = spl_object_id($value);
        if (isset($seen[$oid])) {
            return null;
        }
        $seen[$oid] = true;
        if ($value instanceof \__PHP_Incomplete_Class) {
            /** @var array<string, mixed> $vars */
            $vars = get_object_vars($value);
            $name = $vars['__PHP_Incomplete_Class_Name'] ?? null;
            return is_string($name) && $name !== '' ? $name : '<unknown>';
        }
        foreach (get_object_vars($value) as $propertyValue) {
            $blocked = self::scanForBlockedClass($propertyValue, $seen);
            if ($blocked !== null) {
                return $blocked;
            }
        }
        return null;
    }

    /**
     * @param resource $stream
     * @param mixed $record  serialized TResult or a {__done:true} / {__error:string} sentinel
     */
    private static function writeRecord($stream, mixed $record): void
    {
        $payload = serialize($record);
        $framed  = pack('N', strlen($payload)) . $payload;
        $written = 0;
        $total   = strlen($framed);
        while ($written < $total) {
            $n = @fwrite($stream, substr($framed, $written));
            if ($n === false || $n === 0) {
                return;
            }
            $written += $n;
        }
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<list<T>>
     */
    private static function chunkInto(array $items, int $n): array
    {
        $size = (int)max(1, ceil(count($items) / $n));
        return array_chunk($items, $size);
    }
}
