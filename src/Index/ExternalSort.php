<?php
declare(strict_types=1);

namespace Phpdup\Index;

/**
 * Disk-backed external sort over (string-key, payload) tuples.
 *
 * Used by streaming clustering to merge candidate-pair streams
 * larger than RAM. Splits the input into K-sized run files, sorts
 * each in memory, then K-way merges them on disk.
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
     */
    public function sortStream(iterable $records): \Generator
    {
        $runs = [];
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
        try {
            yield from $this->mergeRuns($runs);
        } finally {
            // Clean up every run file even if the consumer iterates
            // partially, throws, or breaks out of the generator.
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
     *         temp dir) or the run file cannot be opened for writing.
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
            foreach ($buffer as [$k, $v]) {
                fwrite($h, $k . "\t" . $v . "\n");
            }
        } finally {
            fclose($h);
        }
        return $tmp;
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
     */
    private function mergeRuns(array $runs): \Generator
    {
        if ($runs === []) {
            return;
        }
        $handles = [];
        $heads   = [];
        foreach ($runs as $idx => $path) {
            $h = fopen($path, 'r');
            if ($h === false) {
                continue;
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
        try {
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
