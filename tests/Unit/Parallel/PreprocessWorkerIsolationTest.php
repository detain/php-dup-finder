<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Parallel;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Normalization\NormalizationPlugin;
use Phpdup\Parallel\PreprocessWorker;

/**
 * Pins for the Hazard-1 companion fix (improve_logic.md item 0.3): a file
 * that throws mid-processing must contribute EXACTLY one error row and zero
 * block rows. Before buffer-and-discard, every block that happened to be
 * finished before the throw had already leaked into the output list — and
 * PreprocessStage caches precisely the rows it receives, so a truncated
 * per-file list could be persisted as if complete.
 *
 * Also pins the shell-008 streaming twin: PreprocessWorker::processStream
 * yields per file (early-arrival + array/generator parity).
 */
final class PreprocessWorkerIsolationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpdup_p0a_' . uniqid('w', true);
        mkdir($this->tmpDir . '/cache', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testThrowingPluginYieldsOneErrorRowAndDiscardsBufferedBlocksOfThatFile(): void
    {
        // One file, two blocks: the first normalizes cleanly (and would have
        // leaked into the old per-block $out append), the second trips the
        // poison plugin mid-normalize.
        $path = $this->writePhp('poison_single.php', <<<'PHP'
            <?php
            function alpha() { one(); }
            function beta() { two(); }
            PHP);

        $rows = (new PreprocessWorker($this->config(poisoned: true)))->process([$path]);

        $this->assertCount(1, $rows, 'a poison file must emit only its error row');
        $this->assertSame('error', $rows[0]['type']);
        $this->assertSame($path, $rows[0]['file']);
        $this->assertStringContainsString('poisoned:', (string) $rows[0]['message']);
        foreach ($rows as $row) {
            $this->assertNotSame('block', $row['type'], 'buffered alpha block must never surface');
        }
    }

    public function testPoisonedFileDoesNotCostTheRestOfTheBatch(): void
    {
        $clean = $this->writePhp('clean.php', <<<'PHP'
            <?php
            function one() { return 1; }
            function two() { return 2; }
            PHP);
        $poison = $this->writePhp('poison_batch.php', <<<'PHP'
            <?php
            function good() { call_a(); }
            function boom() { call_b(); }
            PHP);

        $rows = (new PreprocessWorker($this->config(poisoned: true)))->process([$clean, $poison]);

        $blockRows  = array_values(array_filter($rows, static fn (array $r): bool => $r['type'] === 'block'));
        $errorRows  = array_values(array_filter($rows, static fn (array $r): bool => $r['type'] === 'error'));
        $this->assertCount(1, $errorRows);
        $this->assertSame($poison, $errorRows[0]['file']);
        $this->assertCount(2, $blockRows, 'the clean file must keep both of its blocks');
        foreach ($blockRows as $row) {
            $this->assertSame($clean, $row['file']);
        }
    }

    public function testCleanBatchUnaffectedByBuffering(): void
    {
        $path = $this->writePhp('plain.php', <<<'PHP'
            <?php
            function alpha() { one(); }
            function beta() { two(); }
            PHP);

        $rows = (new PreprocessWorker($this->config(poisoned: false)))->process([$path]);

        $this->assertCount(2, $rows, 'without the poison plugin both blocks flush normally');
        foreach ($rows as $row) {
            $this->assertSame('block', $row['type']);
            $this->assertSame($path, $row['file']);
            $this->assertArrayHasKey('block', $row);
        }
    }

    /**
     * 0.6: the 'skipped' row promised by process()'s declared contract (and
     * by PreprocessStage's consumer) but never emitted. One zero-block file
     * must produce EXACTLY one row — scalars only, so it satisfies the
     * SOCKETPAIR law (worker wire payloads must survive plain serialize)
     * — and nothing else.
     */
    public function testZeroBlockFileEmitsExactlyOneScalarSkippedRow(): void
    {
        $path = $this->writePhp('empty.php', "<?php\n// comment-only file: parses clean, extracts nothing\n");

        $rows = (new PreprocessWorker($this->config(poisoned: false)))->process([$path]);

        $this->assertCount(1, $rows, 'a zero-block file emits its single skipped row');
        $this->assertSame(['type' => 'skipped', 'file' => $path, 'blocks' => 0], $rows[0]);
        foreach ($rows[0] as $key => $value) {
            $this->assertIsScalar($value, "skipped row field '{$key}' must be scalar for the worker wire");
        }
        $this->assertSame($rows[0], unserialize(serialize($rows[0])), 'skipped row is wire-round-trip safe');
    }

    /**
     * 0.6 balance by construction: every file lands in exactly one bucket
     * — blocks XOR skipped XOR error — so the stage-side counters can never
     * double-count a file.
     */
    public function testEachFileLandsInExactlyOneRowBucket(): void
    {
        $withBlocks = $this->writePhp('blocks.php', "<?php\nfunction a() { x(); }\n");
        $zeroBlock  = $this->writePhp('nothing.php', "<?php\n\n");

        $rows = (new PreprocessWorker($this->config(poisoned: false)))->process([$withBlocks, $zeroBlock]);

        $buckets = [];
        foreach ($rows as $row) {
            $buckets[$row['file']][$row['type']] = ($buckets[$row['file']][$row['type']] ?? 0) + 1;
        }
        $this->assertSame(['block' => 1], $buckets[$withBlocks]);
        $this->assertSame(['skipped' => 1], $buckets[$zeroBlock]);
    }

    /**
     * shell-008 early-arrival pin, worker level: the generator twin must
     * make the FIRST row observable while the chunk's later files are still
     * untouched. The counting plugin turns "not yet processed" into a hard
     * number: at the first pull only the first file's call sites exist.
     * Pre-fix, process()'s array contract forced every file in the batch
     * through the plugin before row 1 could be seen, so the second file's
     * site would already be counted.
     */
    public function testProcessStreamIsLazyPerFile(): void
    {
        StreamingProbePlugin::$visits = 0;
        $first  = $this->writePhp('stream_first.php', "<?php\nfunction a1() { ca(); }\nfunction a2() { cb(); }\n");
        $second = $this->writePhp('stream_second.php', "<?php\nfunction b1() { cb1(); }\n");

        $worker = new PreprocessWorker($this->config(poisoned: false, plugins: [StreamingProbePlugin::class]));
        $gen    = $worker->processStream([$first, $second]);

        $this->assertSame(0, StreamingProbePlugin::$visits, 'the generator must not touch a single file before the first pull');

        $row1 = $gen->current();
        $this->assertIsArray($row1);
        $this->assertSame('block', $row1['type']);
        $this->assertSame($first, $row1['file']);
        $this->assertSame(2, StreamingProbePlugin::$visits, 'first row visible while the chunk last file is still untouched');

        // Manual drain: a foreach/iterator_to_array on a generator that has
        // only been current()-ed re-delivers the position (rewind is a
        // no-op there), so advance explicitly before collecting the rest.
        $gen->next();
        $rest = [];
        while ($gen->valid()) {
            $rest[] = $gen->current();
            $gen->next();
        }
        $this->assertCount(2, $rest, "file one's second block + file two's single block");
        $this->assertSame('block', $rest[0]['type']);
        $this->assertSame($first, $rest[0]['file']);
        $this->assertSame('block', $rest[1]['type']);
        $this->assertSame($second, $rest[1]['file']);
        $this->assertSame(3, StreamingProbePlugin::$visits);
    }

    /**
     * shell-008 parity pin: process() stays the array API — the drained
     * generator twin and the wrapper must produce the very same row stream,
     * row for row (type, file, block presence), including the buffer-and-
     * discard error path and the zero-block skipped path.
     */
    public function testProcessMatchesProcessStreamRowForRow(): void
    {
        $blocks = $this->writePhp('parity_blocks.php', "<?php\nfunction p1() { pa(); }\n");
        $empty  = $this->writePhp('parity_empty.php', "<?php\n// parses, extracts nothing\n");
        $poison = $this->writePhp('parity_poison.php', "<?php\nfunction q1() { qa(); }\nfunction q2() { qb(); }\n");

        // Poisoned registry counts FuncCall sites across the whole batch:
        // pa() is site 1, qa() is site 2 → q1 throws → the poison file
        // contributes exactly one error row (q2 never runs).
        $config = $this->config(poisoned: true);
        $files  = [$blocks, $empty, $poison];

        $shape = static fn (array $rows): array => array_map(
            static fn (array $r): array => [$r['type'], $r['file'], array_key_exists('block', $r)],
            $rows,
        );

        $arrayRows  = (new PreprocessWorker($config))->process($files);
        $streamRows = iterator_to_array((new PreprocessWorker($config))->processStream($files), false);

        $this->assertSame(
            [['block', $blocks, true], ['skipped', $empty, false], ['error', $poison, false]],
            $shape($arrayRows),
            'row stream shape must match the documented per-file contract',
        );
        // process() is currently a drain of processStream(), so this equality arm guards a future re-implementation of process(), not current parity.
        $this->assertSame($shape($arrayRows), $shape($streamRows), 'the array API and the generator twin are row-identical');
    }

    private function config(bool $poisoned, array $plugins = []): Config
    {
        return new Config(
            paths: [$this->tmpDir],
            exclude: [],
            minBlockSize: 1,
            // 'aggressive' is the only mode where CanonicalizingVisitor reaches
            // the plugin pass (strict/default early-return before it).
            normalizationPlugins: $poisoned ? [SecondCallSitePoisonPlugin::class] : $plugins,
            cacheDir: $this->tmpDir . '/cache',
        );
    }

    private function writePhp(string $name, string $source): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $source);
        return $path;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($dir);
    }
}

/**
 * Minimal broken normalization plugin in the documented "broken plugin"
 * failure class of improve_logic.md item 0.3: throws on the second call
 * site it is shown. The registry is built once per worker process(), so
 * the count is deterministic for a given batch order.
 */
final class SecondCallSitePoisonPlugin implements NormalizationPlugin
{
    private int $seenCallSites = 0;

    public function visit(Node $node, string $mode): void
    {
        if ($node instanceof Node\Expr\FuncCall && ++$this->seenCallSites === 2) {
            throw new \RuntimeException('poisoned: normalization plugin refuses the second call site');
        }
    }
}

/**
 * shell-008 witness: counts every FuncCall site it is shown, across files,
 * through a static so the test can read progress from outside the worker.
 * The plugin pass only runs in aggressive mode (Config default), which is
 * what the shared config() helper selects.
 */
final class StreamingProbePlugin implements NormalizationPlugin
{
    public static int $visits = 0;

    public function visit(Node $node, string $mode): void
    {
        if ($node instanceof Node\Expr\FuncCall) {
            ++self::$visits;
        }
    }
}
