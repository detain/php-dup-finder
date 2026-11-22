<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use Phpdup\Index\ExternalSort;

final class ExternalSortTest extends TestCase
{
    public function testEmptyInputProducesEmptyOutput(): void
    {
        $sorter = new ExternalSort(sys_get_temp_dir(), runSize: 4);
        $out = iterator_to_array($sorter->sortStream([]), false);
        $this->assertSame([], $out);
    }

    public function testSortsAcrossRuns(): void
    {
        $sorter = new ExternalSort(sys_get_temp_dir(), runSize: 3);
        $records = [
            ['c', '3'],
            ['a', '1'],
            ['e', '5'],
            ['b', '2'],
            ['d', '4'],
        ];
        $sorted = iterator_to_array($sorter->sortStream($records), false);
        $keys = array_column($sorted, 0);
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $keys);
    }

    public function testPreservesPayloads(): void
    {
        $sorter = new ExternalSort(sys_get_temp_dir(), runSize: 2);
        $records = [['z', 'last'], ['a', 'first']];
        $sorted = iterator_to_array($sorter->sortStream($records), false);
        $this->assertSame([['a', 'first'], ['z', 'last']], $sorted);
    }

    // ---------------------------------------------------------------
    // engine-027 legs (engine-S31b): merge re-open failure and
    // short-write failure must abort loudly, never lose records.
    // ---------------------------------------------------------------

    public function testMergeAbortsWhenARunCannotBeReopened(): void
    {
        // Leg 1 direct: mergeRuns used to `continue` past an
        // unopenable run file — silently dropping that run's records
        // from the merged stream. Now it throws, mirroring flushRun's
        // posture on its own open failures.
        $merge = new \ReflectionMethod(ExternalSort::class, 'mergeRuns');
        $gen = $merge->invoke(
            new ExternalSort(sys_get_temp_dir()),
            ['/phpdup-s31b-no-such-dir/run-0'],
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot re-open run file');
        iterator_to_array($gen, false);
    }

    public function testEndToEndSortAbortsWhenTempDirIsPrunedMidStream(): void
    {
        $tempDir = $this->freshTempDir();
        try {
            // runSize 1 ⇒ the first record spills before the source
            // is pulled again; the source generator then deletes the
            // spilled run (simulating a reaped temp dir — fd limits
            // and tmpfs pruners bite exactly under the pressure this
            // class exists for). The merge must abort, not skip.
            $records = (function () use ($tempDir): \Generator {
                yield ['a', '1'];
                foreach (glob($tempDir . '/phpdup-extsort-*') ?: [] as $f) {
                    @unlink($f);
                }
            })();
            $sorter = new ExternalSort($tempDir, runSize: 1);
            try {
                iterator_to_array($sorter->sortStream($records), false);
                $this->fail('pruned run file must abort the sort, not vanish silently');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('cannot re-open run file', $e->getMessage());
            }
            $this->assertSame(
                [],
                glob($tempDir . '/phpdup-extsort-*') ?: [],
                'failure path must leave no run files behind',
            );
        } finally {
            $this->removeTempDir($tempDir);
        }
    }

    public function testAbandonedConsumerStillCleansUpRunFiles(): void
    {
        $tempDir = $this->freshTempDir();
        try {
            $sorter  = new ExternalSort($tempDir, runSize: 1);
            $records = [['b', '2'], ['a', '1'], ['c', '3']];
            $gen = $sorter->sortStream($records);
            foreach ($gen as $ignored) {
                break; // abandon mid-merge
            }
            unset($gen); // destroy the generator → finally block runs
            $this->assertSame(
                [],
                glob($tempDir . '/phpdup-extsort-*') ?: [],
                'partially consumed sort must still unlink every spilled run',
            );
        } finally {
            $this->removeTempDir($tempDir);
        }
    }

    public function testFailedFlushLeavesNoCorruptRunFileBehind(): void
    {
        // engine-S31b fix-round-1 (reviewer F-1): the short-write pin
        // below only exercises the writeRunLines() seam, and the
        // temp-dir-prune pin above tests the MERGE throw — so nothing
        // pinned flushRun()'s own cleanup. When a flush aborts mid
        // write, tempnam has already created the run file but the
        // path never reaches sortStream()'s $runs list (flushRun only
        // returns on success), so without flushRun's own unlink the
        // corrupt run leaks — the one throw path that denied the
        // "every run file already created is unlinked" @throws claim.
        //
        // Hermetic trigger: the second record carries a non-stringable
        // payload, so the write seam's string concatenation throws
        // from inside flushRun() on the REAL tempnam file (the same
        // guard any short-write \RuntimeException travels). runSize 1
        // makes the first record spill normally, so this also proves
        // the caller's finally sweeps the already-spilled run while
        // flushRun's guard sweeps the corrupt one: the temp dir ends
        // up completely empty.
        $tempDir = $this->freshTempDir();
        try {
            $sorter = new ExternalSort($tempDir, runSize: 1);
            try {
                iterator_to_array($sorter->sortStream([
                    ['a', '1'],
                    ['b', new \stdClass()], // poison: aborts the second flush
                ]), false);
                $this->fail('a run that cannot be written in full must abort the sort');
            } catch (\Throwable $e) {
                $this->assertStringContainsString(
                    'could not be converted to string',
                    $e->getMessage(),
                    'expected the poison payload to abort the flush',
                );
            }
            $this->assertSame(
                [],
                glob($tempDir . '/*') ?: [],
                'flush-failure path must leave zero files in the temp dir',
            );
        } finally {
            $this->removeTempDir($tempDir);
        }
    }

    public function testShortWriteAbortsTheRunFlush(): void
    {
        // Leg 2 direct: flushRun used to trust fwrite's return — a
        // disk-full partial write produced a corrupt run the merge
        // read back as a legitimately short file. The byte-length
        // check in writeRunLines aborts instead. The fail-write
        // wrapper below returns -1 from stream_write, so fwrite()
        // comes back false with no warning (probe-verified) — fully
        // hermetic under phpunit.xml's failOnWarning="true".
        if (!in_array('phpdup-s31b-failwrite', stream_get_wrappers(), true)) {
            stream_wrapper_register('phpdup-s31b-failwrite', S31bWriteFailingStream::class);
        }
        $h = fopen('phpdup-s31b-failwrite://run', 'w');
        $this->assertNotFalse($h, 'fail-write wrapper must open for writing');
        try {
            $write = new \ReflectionMethod(ExternalSort::class, 'writeRunLines');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('short write');
            $write->invoke(new ExternalSort(sys_get_temp_dir()), $h, [['k', 'v']], 'phpdup-s31b-failwrite://run');
            $this->fail('unreachable: the write check must throw');
        } finally {
            fclose($h);
        }
    }

    public function testWriteRunLinesEmitsExactByteLinesIncludingMultibyte(): void
    {
        // Positive path of the same seam: the check compares against
        // BYTE length, so multibyte payloads must not false-trip it
        // (a character-count comparison would throw here — that is
        // the mutation this pin catches).
        $h = fopen('php://temp', 'w+');
        $this->assertNotFalse($h);
        try {
            $write = new \ReflectionMethod(ExternalSort::class, 'writeRunLines');
            $write->invoke(
                new ExternalSort(sys_get_temp_dir()),
                $h,
                [["kë", 'ünïcodé'], ['plain', 'x']],
                'php://temp',
            );
            rewind($h);
            $bytes = stream_get_contents($h);
        } finally {
            fclose($h);
        }
        $this->assertSame("kë\tünïcodé\nplain\tx\n", $bytes);
    }

    private function freshTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-s31b-' . bin2hex(random_bytes(6));
        $this->assertNotFalse(mkdir($dir, 0o775, true), 'test temp dir must be creatable');
        return $dir;
    }

    private function removeTempDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}

/**
 * Test-only stream wrapper whose writes always fail: stream_write
 * returns -1, so fwrite() returns false without emitting a warning
 * (verified against this PHP). Stands in for ENOSPC on the run file
 * without touching real disk quotas — hermetic on every host.
 */
final class S31bWriteFailingStream
{
    /** @var mixed */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return -1;
    }

    public function stream_close(): void
    {
    }
}
