<?php
declare(strict_types=1);

namespace Phpdup\Parallel;

/**
 * pcntl_fork-based worker pool for embarrassingly parallel batch work.
 *
 * Usage:
 *
 *     $pool = new WorkerPool(workers: 4);
 *     $results = $pool->run($items, fn($itemBatch) => processBatch($itemBatch));
 *
 * Items are partitioned into chunks, each chunk forked into a child
 * process. Children write their result via PHP serialize() to a temp
 * file; the parent reads and concatenates results in deterministic
 * order (chunk index ascending).
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
        $candidates = [
            (int)getenv('PHPDUP_WORKERS'),
            self::nprocFromCmdline(),
            (int)shell_exec('nproc 2>/dev/null'),
            4,
        ];
        foreach ($candidates as $n) {
            if ($n > 0) return $n;
        }
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

        foreach ($chunks as $idx => $chunk) {
            $tmpFiles[$idx] = tempnam(sys_get_temp_dir(), 'phpdup-w');
            $pid = pcntl_fork();
            if ($pid === -1) {
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
            $data = @unserialize($blob);
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
