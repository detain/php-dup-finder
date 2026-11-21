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

                        $record = self::decodeFrame($idx, $payload, $allowList);
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
     * PROTECTED $attributes are deliberately not traversed: in the pipeline's
     * payloads they hold only string-keyed scalars (parser line/token
     * positions plus the string ATTR_OP markers — AstParser runs no
     * NameResolver), never a class instance the allow-list could miss.
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
