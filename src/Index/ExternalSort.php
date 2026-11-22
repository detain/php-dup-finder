<?php
declare(strict_types=1);

namespace Phpdup\Index;

/**
 * Disk-backed external sort over (string-key, payload) tuples.
 *
 * Built for streaming clustering: merging candidate-pair streams
 * larger than RAM. Splits the input into K-sized run files, sorts
 * each in memory, then K-way merges them on disk.
 *
 * NOT WIRED to any production code path (engine-028 catalog row 10,
 * dispositioned KEEP by the engine-S31a ledger, executed here in
 * engine-S31b); nothing in src/ or bin/ instantiates it today.
 *
 * I/O posture (engine-027, fixed by this step): every spilling and
 * merge failure throws \RuntimeException — a run that cannot be
 * re-opened for merging, or a line that cannot be written in full,
 * aborts the sort instead of silently shrinking the merged stream.
 *
 * The on-disk format is one record per line:
 *
 *     <key>\t<payload>
 *
 * Caller-supplied keys must not contain tab/newline.
 */
final class ExternalSort
{
    public function __construct(
        private readonly string $tempDir,
        private readonly int $runSize = 100_000,
    ) {
        if ($tempDir !== '' && !is_dir($tempDir)) {
            @mkdir($tempDir, 0o775, true);
        }
    }

    /**
     * @param iterable<array{0:string,1:string}> $records  (key, payload) pairs
     * @return \Generator<int, array{0:string,1:string}>  sorted-by-key
     * @throws \RuntimeException When a run cannot be spilled, fully
     *         written, or re-opened for merging (engine-027) — every
     *         run file already created is unlinked on the way out.
     */
    public function sortStream(iterable $records): \Generator
    {
        $runs = [];
        try {
            $buffer = [];
            $count  = 0;
            foreach ($records as [$key, $payload]) {
                $buffer[] = [$key, $payload];
                if (++$count >= $this->runSize) {
                    $runs[] = $this->flushRun($buffer);
                    $buffer = [];
                    $count  = 0;
                }
            }
            if ($buffer !== []) {
                $runs[] = $this->flushRun($buffer);
            }
            yield from $this->mergeRuns($runs);
        } finally {
            // Clean up every run file even if a flush or merge
            // throws, or the consumer iterates partially and breaks
            // out of the generator.
            foreach ($runs as $f) {
                @unlink($f);
            }
        }
    }

    /**
     * Sort a buffered run in memory and persist it to a tempnam file.
     *
     * @param list<array{0:string,1:string}> $buffer
     * @return string Path to the on-disk sorted run.
     * @throws \RuntimeException When tempnam returns false (no writable
     *         temp dir), the run file cannot be opened for writing,
     *         or a line cannot be written in full (engine-027).
     */
    private function flushRun(array $buffer): string
    {
        usort($buffer, static fn(array $a, array $b) => strcmp($a[0], $b[0]));
        $tmp = tempnam($this->tempDir, 'phpdup-extsort-');
        if ($tmp === false) {
            throw new \RuntimeException(
                "ExternalSort: tempnam() failed in {$this->tempDir}"
            );
        }
        $h = fopen($tmp, 'w');
        if ($h === false) {
            // Make sure we don't leak the empty placeholder tempnam created.
            @unlink($tmp);
            throw new \RuntimeException("ExternalSort: cannot open run file {$tmp}");
        }
        try {
            $this->writeRunLines($h, $buffer, $tmp);
        } finally {
            fclose($h);
        }
        return $tmp;
    }

    /**
     * Persist a sorted buffer as one "<key>\t<payload>\n" line per
     * record, verifying each write consumed the full line.
     *
     * @param resource $h        run-file handle opened for writing
     * @param list<array{0:string,1:string}> $buffer
     * @param string   $path     run file path, for the error text
     * @throws \RuntimeException When fwrite returns fewer bytes than
     *         the line's byte length (e.g. ENOSPC) — engine-027's
     *         corrupt-run path. Byte length is the right basis:
     *         fwrite counts bytes, so multibyte payloads compare
     *         consistently.
     */
    private function writeRunLines($h, array $buffer, string $path): void
    {
        foreach ($buffer as [$k, $v]) {
            $line = $k . "\t" . $v . "\n";
            // engine-027: an unchecked fwrite can return fewer bytes
            // than the line (disk full) and leave a corrupt run that
            // mergeRuns reads back as a legitimately short file.
            $written = fwrite($h, $line);
            if ($written !== strlen($line)) {
                throw new \RuntimeException(
                    "ExternalSort: short write to run file {$path}"
                    . ($written === false
                        ? " (fwrite failed)"
                        : " (wrote {$written} of " . strlen($line) . " bytes)")
                );
            }
        }
    }

    /**
     * Linear K-way merge: keep the head line of every run buffered,
     * pick the lexicographically smallest, advance that run.
     *
     * O(N × K) where N is total records and K is run count. Run count
     * is `ceil(N / runSize)`, which for the default runSize of 100k
     * keeps K small enough that linear scan beats heap overhead.
     *
     * @param list<string> $runs
     * @return \Generator<int, array{0:string,1:string}>
     * @throws \RuntimeException When a run file cannot be re-opened
     *         (engine-027: the previous `continue` dropped that run's
     *         records from the merged stream in silence).
     */
    private function mergeRuns(array $runs): \Generator
    {
        if ($runs === []) {
            return;
        }
        $handles = [];
        $heads   = [];
        try {
            foreach ($runs as $idx => $path) {
                // engine-027: fd limits / pruned temp dirs bite
                // exactly under the memory pressure this class
                // exists for — losing a whole run silently is worse
                // than a loud failure. Mirror flushRun's posture.
                $h = @fopen($path, 'r');
                if ($h === false) {
                    throw new \RuntimeException(
                        "ExternalSort: cannot re-open run file {$path} for merging"
                    );
                }
                $handles[$idx] = $h;
                $line = fgets($h);
                if ($line !== false) {
                    $heads[$idx] = rtrim($line, "\n");
                } else {
                    @fclose($h);
                    unset($handles[$idx]);
                }
            }
            while ($heads !== []) {
                $minIdx = null;
                $minKey = null;
                foreach ($heads as $idx => $line) {
                    $tab = strpos($line, "\t");
                    $key = $tab === false ? $line : substr($line, 0, $tab);
                    if ($minKey === null || strcmp($key, $minKey) < 0) {
                        $minKey = $key;
                        $minIdx = $idx;
                    }
                }
                if ($minIdx === null) {
                    break;
                }
                $line = $heads[$minIdx];
                $tab = strpos($line, "\t");
                if ($tab !== false) {
                    yield [substr($line, 0, $tab), substr($line, $tab + 1)];
                }

                $next = fgets($handles[$minIdx]);
                if ($next === false || $next === '') {
                    @fclose($handles[$minIdx]);
                    unset($handles[$minIdx], $heads[$minIdx]);
                } else {
                    $heads[$minIdx] = rtrim($next, "\n");
                }
            }
        } finally {
            foreach ($handles as $h) {
                @fclose($h);
            }
        }
    }
}
