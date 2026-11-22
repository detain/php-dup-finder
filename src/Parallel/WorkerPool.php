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
     * shell-S13 (php-dup-finder-shell-050): fixed window for one fwrite of a
     * framed record. The pre-fix loop handed fwrite the WHOLE shrinking
     * remainder (`substr($framed, $written)`) on every attempt, so each
     * partial write under backpressure re-copied every remaining byte —
     * quadratic memcpy churn exactly when the parent drains slowly. A fixed
     * window bounds every intermediate copy at this many bytes, making total
     * copy work linear in the record size.
     */
    private const WRITE_WINDOW = 65536;

    /**
     * shell-S13: how long a would-block parks per stream_select pulse while
     * waiting for the peer to drain. The select call sleeps in the kernel
     * until the stream turns writable, so this is the wake-up clock (and the
     * EINTR re-check cadence), not a spin interval — it matches the one
     * second pulse the parent-side drain loop uses.
     */
    private const WRITE_WAIT_PULSE_SECONDS = 1;

    /**
     * shell-S13: consecutive writable wake-ups that accepted zero further
     * bytes before the write is abandoned. Writability is reported on
     * low-water-mark granularity: a select can answer "writable" a moment
     * before the kernel would still only take a fraction of the window, and
     * an AF_UNIX send that can push at least one byte of the window may still
     * return 0. Genuine slow readers always make progress (the counter
     * resets on any accepted byte), so this ceiling only ever trips on a
     * stream that wakes us and refuses to take data — a stall, which must
     * end the write instead of spinning forever on it (the pool's EOF-
     * without-sentinel lifecycle then surfaces the truncated stream).
     */
    private const WRITE_STALL_LIMIT = 16;

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
     * @param (\Closure(): bool)|null $cancel shell-S12 (shell-041)
     *        cooperative-cancel probe. Polled once before anything starts
     *        (a pre-armed cancel forks NOTHING and runs the serial task
     *        nothing either — the generator just ends) and again after
     *        every {@see stream_select()} wake-up, including the 1-second
     *        timeout arm and the EINTR arm that lets dispatched SIGINT
     *        handlers flip the pipeline's cancel flag. When it returns
     *        true the stream ENDS PROMPTLY AND QUIET: the drain loop
     *        breaks, the exit-status verification pass is skipped (it
     *        exists for ACCIDENTAL child deaths — children we are about
     *        to SIGTERM on purpose would all "fail" it, and throwing
     *        there would turn a deliberate abort into a false alarm),
     *        and the finally still SIGTERMs + reaps the whole set. A
     *        stalled child no longer spins the loop forever: before this
     *        parameter existed the consumer could only notice a cancel at
     *        a yield boundary it never reached. `null` (the default)
     *        keeps the pre-S12 behaviour byte-for-byte. The serial
     *        fallback is gated by the entry probe only — between-records
     *        granularity there is the task's and the consumer's own loop.
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
     *         (shell-005, shell-028, shell-S10). A run ended by the
     *         shell-S12 cancel probe is NOT such a case: it ends without
     *         error by contract.
     */
    public function runStreaming(array $items, \Closure $task, ?\Closure $cancel = null): \Generator
    {
        if (!$items) return;

        // shell-S12 (shell-041): a pre-armed cancel means the consumer has
        // already given up before the first pull — start nothing at all.
        if ($cancel !== null && $cancel()) {
            return;
        }

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
        // Hoisted per run; since shell-S11 the list itself is memoized
        // inside SerializedClassAllowList (shell-034), so even a fresh
        // call is a pointer reuse — the hoist stays because this is the
        // hot decode path and the frame loop reads it per record.
        $allowList = SerializedClassAllowList::blockCacheClasses();

        // shell-S12: set when the drain loop broke on the cancel probe
        // instead of draining all pipes; suppresses the exit-status
        // verification (deliberate cancels must not throw, see below).
        $cancelledRun = false;

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
                // shell-S12 (shell-041): the cancellation pulse. With a
                // stalled child the select loop used to spin its 1-second
                // timeouts forever — the consumer's cancel poll sits at a
                // yield boundary it could never reach. Every wake-up (data,
                // timeout, or EINTR after the dispatched SIGINT flipped
                // state->cancelled) passes this check first, so a cancel
                // lands within one pulse.
                if ($cancel !== null && $cancel()) {
                    $cancelledRun = true;
                    break;
                }
                $read   = $pipes;
                $write  = null;
                $except = null;
                $ready  = @stream_select($read, $write, $except, 1, 0);
                if ($ready === false) {
                    // EINTR can show up here when a signal arrives mid-select; just retry.
                    // Since shell-S12 the retry's head-check turns a SIGINT that
                    // only signalled the handler into a prompt cooperative abort.
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

            // shell-S12: cooperative abort. Every child left in $pids is
            // about to be SIGTERMed and reaped by the finally below — the
            // S10 exit-status logic would (correctly, for ACCIDENTAL
            // deaths) throw on each of them as a signal death without a
            // sentinel. A deliberate cancel is not an accident: end the
            // generator quietly. No orphan outlives this path; the finally
            // owns the TERM + bounded reap.
            if ($cancelledRun) {
                return;
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
     * Three reject classes, all previously silent corruption or silent
     * slowness:
     *  - @unserialize() returning false on a non-decodable payload. A valid
     *    record can never serialize to an invalid payload (writeRecord owns
     *    the encoder), so false here means a broken/truncated frame — the
     *    old code yielded that false straight into the results stream.
     *  - an object whose class is missing from the allow-list arriving as
     *    __PHP_Incomplete_Class (a wire type whose author forgot to
     *    extend the list, or a child coaxed into serializing one).
     *    Pre-S11 the guard was a recursive walk of the WHOLE decoded
     *    graph run over EVERY frame — measured at 2.13x the cost of the
     *    unserialize it gated (447 ms scan vs 210 ms decode). shell-S11
     *    replaced the unconditional walk with a C-speed candidate pass
     *    over the frame's own bytes
     *    ({@see SerializedClassAllowList::firstUnallowlistedClassHeader()}):
     *    the serializer spells a literal class header for every
     *    instance it writes, so a frame whose headers all name classes
     *    that are on the list AND resolvable provably cannot decode an
     *    incomplete — it skips the walk entirely. Any doubt (an off-
     *    list name, a forged header framing, an enum token) routes the
     *    frame through the shared graph walk for the baseline verdict.
     *    Decision-equivalence is pinned in WorkerPoolTest: poison
     *    nested three deep is still refused by name, serialized-
     *    lookalike bait still yields, real graph-wire records still
     *    restore DAG-shared.
     *    Side effect worth naming: a blocked class parked in a TYPED
     *    slot used to escape decodeFrame as a raw "Cannot assign
     *    __PHP_Incomplete_Class to property ..." TypeError mid-decode;
     *    the suspect path now refuses it descriptively instead.
     *
     * Sentinel precedence is unaffected: __error/__done frames are plain
     * arrays of string/bool (the child writes only those shapes) — zero
     * class headers, so the gate clears them and both guards can never
     * fire on a well-formed control frame before the caller inspects it.
     *
     * @param list<class-string> $allowList
     */
    private static function decodeFrame(int $idx, string $payload, array $allowList): mixed
    {
        $suspect = SerializedClassAllowList::firstUnallowlistedClassHeader($payload, $allowList);
        try {
            $record = @unserialize($payload, ['allowed_classes' => $allowList]);
        } catch (\TypeError $t) {
            if ($suspect === null || $suspect === SerializedClassAllowList::SCAN_UNDECIDABLE) {
                throw $t; // nothing to name — the baseline's own escape, kept verbatim
            }
            // A blocked class parked in a typed slot of an allowed
            // class: PHP throws WHILE assigning the incomplete, mid-
            // decode. The frame was named by the header gate anyway —
            // refuse descriptively rather than leak the type system.
            throw self::blockedFrameException($idx, $suspect);
        }
        if ($record === false) {
            $preview = addcslashes(substr($payload, 0, 32), "\0..\37\177..\377");
            throw new \RuntimeException(
                "Worker $idx sent a frame that failed to decode: unserialize() returned false for " .
                strlen($payload) . " bytes (payload starts: \"$preview\"). " .
                'Refusing to continue with corrupt data.'
            );
        }
        if ($suspect === null) {
            return $record; // gate-cleared: the walk could only have found nothing
        }
        $blocked = SerializedClassAllowList::findIncompleteClass($record);
        if ($blocked === SerializedClassAllowList::SCAN_UNRESOLVED) {
            // The graph walk is bounded; an out-budget frame can be
            // neither screened nor trusted, so it fails loud rather
            // than yielding unchecked data (the stores' miss posture
            // has no wire analogue).
            throw new \RuntimeException(
                "Worker $idx sent a frame the class-header gate could not clear AND whose " .
                'decoded graph exceeded the blocked-class scan budget — refusing to yield unchecked data.'
            );
        }
        if ($blocked !== null) {
            throw self::blockedFrameException($idx, $blocked);
        }
        // Bait or restored-real: the unlisted name was spelled only
        // inside string data (or an E: token that decodes to a real
        // enum) and the authoritative walk cleared the graph — the
        // baseline chain walked the same graph and yielded here too.
        return $record;
    }

    /**
     * The one blocked-class refusal wording, shared by the typed-slot
     * refusal and the graph-walk refusal so every blocked frame speaks
     * identically (WireTest pins: the message carries 'allow-list' and
     * the offending name).
     */
    private static function blockedFrameException(int $idx, string $blocked): \RuntimeException
    {
        return new \RuntimeException(
            "Worker $idx sent a frame embedding an object of class '$blocked', which is missing from the " .
            "unserialize allow-list (SerializedClassAllowList::blockCacheClasses()) — " .
            'extend the allow-list for the new wire class. Refusing to continue with corrupt data.'
        );
    }

    /**
     * Write one length-prefixed frame, coping with partial writes LINEARLY.
     *
     * shell-S13 (php-dup-finder-shell-050): a record bigger than the socket
     * buffer leaves fwrite short of the framed length — the kernel answers
     * with a partial count when only part of the window fits and with 0 when
     * the peer queue is full. The pre-fix loop was broken for exactly that
     * shape twice over: it re-sliced the whole shrinking remainder per
     * attempt (quadratic copies under backpressure) AND it treated every
     * short/zero return as fatal, silently truncating the frame — a stalled
     * parent then produced a corrupt stream whose tail never existed,
     * laundered into the pool as if it had been delivered.
     *
     * Now: a fixed {@see WRITE_WINDOW} cursor walks the original framed
     * string (each copy is bounded, total work linear); any accepted byte
     * advances the cursor and the write resumes at it; a zero return is
     * resolved by the stream's own mode via {@see awaitWritable()} — never
     * a busy-spin, never a give-up mid-frame. A hard stream error (false:
     * a closed parent end, EPIPE) and a permanently stalled stream still
     * end the write, and the unchanged error taxonomy routes them: the
     * half-written frame is met by the drain loop's EOF path and S10's
     * exit-status check refuses a sentinel-less stream — the stuck peer
     * surfaces there instead of being absorbed here.
     *
     * @param resource $stream
     * @param mixed $record  serialized TResult or a {__done:true} / {__error:string} sentinel
     */
    private static function writeRecord($stream, mixed $record): void
    {
        $payload = serialize($record);
        $framed  = pack('N', strlen($payload)) . $payload;
        $cursor  = 0;
        $total   = strlen($framed);
        $stalled = 0;

        while ($cursor < $total) {
            // Fixed window into the original string — never the shrinking
            // remainder (shell-050: each copy is at most WRITE_WINDOW bytes).
            $n = @fwrite($stream, substr($framed, $cursor, self::WRITE_WINDOW));
            if ($n === false) {
                return; // broken pipe / hard error: the pool surfaces the dead child
            }
            if ($n === 0) {
                if ($stalled >= self::WRITE_STALL_LIMIT || !self::awaitWritable($stream)) {
                    return; // a stall or an unwaitable refusal: end, do not spin
                }
                $stalled++;
                continue; // retry the SAME window from the SAME cursor — linear resume
            }
            $cursor += $n;
            $stalled = 0;
        }
    }

    /**
     * Park (event-driven, no busy-spin) until $stream may accept bytes again.
     *
     * shell-S13 mode honesty: the same `fwrite() === 0` return means two
     * different things depending on how the stream is armed. A BLOCKING
     * stream has already waited in the kernel for whatever it could get, so
     * zero there is an error posture — return false and let the caller end
     * the write on the existing surfacing path. A NON-BLOCKING stream
     * reports a full peer queue with zero; that is a pause, not a death, and
     * the frame must survive it, so wait on the stream's writability through
     * stream_select: the select call sleeps in the kernel until the peer
     * drains (the one-second pulse is the wake-up clock, never a spin
     * interval, and it doubles as the EINTR re-check cadence, mirroring the
     * drain loop above). A stream select can keep no answer for at all
     * (EINTR, or a type it cannot cast — the latter also throws ValueError,
     * which folds into the same posture) gets two pulses before the wait is
     * declared mute: an isolated EINTR resumes the normal wait, a mute
     * stream stops instead of spinning on it.
     *
     * There is deliberately no self-imposed write deadline: the child that
     * reaches this wait is streaming to the parent's drain, and the lifecycle
     * that decides the parent is gone or cancelled already exists (S12's
     * cooperative cancel + the finally's SIGTERM/reap, with once-S45 keeping
     * that kill switch real; a closed peer unwinds to the false arm in
     * {@see writeRecord()}). Laundering a stuck-but-alive reader into a
     * timeout here would drop frames the pool may still collect.
     *
     * @param resource $stream
     */
    private static function awaitWritable($stream): bool
    {
        // stream_get_meta_data() documents 'blocked', but not every wrapper
        // reports it (php://temp carries no such key at runtime), so the
        // mode default is supplied as a key-union rather than trusting the
        // offset to exist.
        $meta = (array) @stream_get_meta_data($stream) + ['blocked' => true];
        if ($meta['blocked'] !== false) {
            // Blocking mode (or a stream that cannot name its mode): the
            // zero return is already the kernel's final word on this write.
            return false;
        }

        $unanswered = 0;
        while (true) {
            $read     = [];
            $write    = [$stream];
            $except   = null;
            $selectOk = true;
            try {
                $ready = @stream_select($read, $write, $except, self::WRITE_WAIT_PULSE_SECONDS, 0);
            } catch (\ValueError) {
                $ready   = false; // no castable stream array: select cannot answer for this stream
                $selectOk = false;
            }
            if ($ready === false) {
                if (function_exists('pcntl_signal_dispatch') && $selectOk) {
                    pcntl_signal_dispatch(); // EINTR posture: service handlers, then re-ask
                }
                if (++$unanswered >= 2) {
                    return false; // a mute stream is not a stream we can wait on
                }
                continue; // give it one more pulse: an isolated EINTR must not truncate
            }
            if ($ready === 0) {
                continue; // the peer has not drained yet — sleep in the kernel, not in a loop
            }
            return true; // writable again: retry the same window
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
