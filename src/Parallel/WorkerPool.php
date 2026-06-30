<?php
declare(strict_types=1);

namespace Phpdup\Parallel;

/**
 * pcntl_fork-based worker pool for embarrassingly parallel batch work.
 *
 * Two modes:
 *
 *   - {@see run()} — collect-and-return. Each child writes its full result
 *     to a temp file when finished; the parent reads them all once every
 *     child has exited.
 *   - {@see runStreaming()} — yield-as-results-arrive. Each child streams
 *     records through a per-process socketpair as soon as they're
 *     produced; the parent {@see stream_select()}s across all children and
 *     the returned {@see \Generator} yields each record live. Used by the
 *     cooperative pipeline so the TUI can repaint mid-stage.
 *
 * Both modes share the same chunking / serialization / fallback rules.
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

    public static function detectCpuCount(): int
    {
        static $cache = null;

        // PHPDUP_WORKERS always short-circuits: env wins over any cached value.
        $env = (int)getenv('PHPDUP_WORKERS');
        if ($env > 0) {
            $cache = $env;
            return $env;
        }

        if ($cache !== null) {
            return $cache;
        }

        $candidates = [
            self::nprocFromCmdline(),
            (int)shell_exec('nproc 2>/dev/null'),
            4,
        ];
        foreach ($candidates as $n) {
            if ($n > 0) {
                $cache = $n;
                return $n;
            }
        }
        $cache = 1;
        return 1;
    }

    private static function nprocFromCmdline(): int
    {
        if (!is_readable('/proc/cpuinfo')) return 0;
        $cpu = @file_get_contents('/proc/cpuinfo');
        if ($cpu === false) return 0;
        return substr_count($cpu, "processor\t:");
    }

    /**
     * Run $task on each chunk of $items split into roughly equal batches.
     *
     * @template TItem
     * @template TResult
     * @param list<TItem> $items
     * @param \Closure(list<TItem>): list<TResult> $task
     * @return list<TResult>  flattened results in input order across batches
     *
     * @note A `try/finally` block wraps the entire body to guarantee that all
     *   temp files created for inter-process result transfer are deleted on
     *   any exit path (success, fork failure, or thrown exception). This
     *   prevents temp-file leakage when `pcntl_fork` fails mid-batch.
     */
    public function run(array $items, \Closure $task): array
    {
        if (!$items) return [];
        $workers = max(1, $this->workers > 0 ? $this->workers : self::detectCpuCount());
        if ($workers === 1 || !self::isAvailable() || count($items) < 8) {
            return $task($items);
        }

        $chunks = self::chunkInto($items, $workers);
        $tmpFiles = [];
        $pids = [];

        try {
            foreach ($chunks as $idx => $chunk) {
                $tmpFiles[$idx] = tempnam(sys_get_temp_dir(), 'phpdup-w');
                $pid = pcntl_fork();
                if ($pid === -1) {
                    self::unlinkAll($tmpFiles);
                    throw new \RuntimeException('pcntl_fork failed');
                }
                if ($pid === 0) {
                    // child
                    try {
                        $result = $task($chunk);
                        file_put_contents($tmpFiles[$idx], serialize($result), LOCK_EX);
                        exit(0);
                    } catch (\Throwable $e) {
                        file_put_contents($tmpFiles[$idx], serialize(['__error' => $e->getMessage() . "\n" . $e->getTraceAsString()]), LOCK_EX);
                        exit(1);
                    }
                }
                $pids[$idx] = $pid;
            }

            $exitCodes = [];
            foreach ($pids as $idx => $pid) {
                pcntl_waitpid($pid, $status);
                $exitCodes[$idx] = pcntl_wexitstatus($status);
            }

            $results = [];
            foreach ($tmpFiles as $idx => $file) {
                $blob = @file_get_contents($file);
                @unlink($file);
                if ($blob === false || $blob === '') {
                    if ($exitCodes[$idx] !== 0) {
                        throw new \RuntimeException("Worker $idx exited {$exitCodes[$idx]} with no output");
                    }
                    continue;
                }
                $data = @unserialize($blob, ['allowed_classes' => false]);
                if (is_array($data) && isset($data['__error'])) {
                    throw new \RuntimeException("Worker $idx failed: " . $data['__error']);
                }
                if (!is_array($data)) {
                    throw new \RuntimeException("Worker $idx returned non-array");
                }
                foreach ($data as $entry) {
                    $results[] = $entry;
                }
            }
            return $results;
        } finally {
            self::unlinkAll($tmpFiles);
        }
    }

    /**
     * Streaming variant of {@see run()}. The task closure may return either an
     * array or a {@see \Generator} of records; this method yields each record
     * to the caller as soon as it arrives from a child process, instead of
     * waiting for the whole pool to finish.
     *
     * Falls back to serial in-process iteration when {@see isAvailable()}
     * returns false, when workers ≤ 1, or for trivially small inputs (<8
     * items) — same fallback rules as {@see run()}.
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
     * no orphaned workers linger on stream_select().
     */
    public function runStreaming(array $items, \Closure $task): \Generator
    {
        if (!$items) return;

        $workers = max(1, $this->workers > 0 ? $this->workers : self::detectCpuCount());
        if ($workers === 1 || !self::isAvailable() || count($items) < 8) {
            yield from $task($items);
            return;
        }

        $chunks   = self::chunkInto($items, $workers);
        $pipes    = [];   // parent socket per chunk index
        $pids     = [];
        $buffers  = [];
        $gcCounter = 0;

        foreach ($chunks as $idx => $chunk) {
            $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) {
                throw new \RuntimeException('stream_socket_pair failed');
            }
            [$childEnd, $parentEnd] = $pair;

            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('pcntl_fork failed');
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
        }

        try {
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

                        $record = @unserialize($payload, ['allowed_classes' => false]);
                        if (is_array($record) && isset($record['__error'])) {
                            throw new \RuntimeException("Worker $idx failed: " . $record['__error']);
                        }
                        if (is_array($record) && isset($record['__done'])) {
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
        } finally {
            foreach ($pipes as $sock) @fclose($sock);
            $status = 0;
            foreach ($pids as $pid) {
                $waitResult = @pcntl_waitpid($pid, $status, WNOHANG);
                if ($waitResult === 0) {
                    @posix_kill($pid, SIGTERM);
                    @pcntl_waitpid($pid, $status);
                }
            }
        }
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

    /**
     * @param list<string> $files
     */
    private static function unlinkAll(array $files): void
    {
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}
