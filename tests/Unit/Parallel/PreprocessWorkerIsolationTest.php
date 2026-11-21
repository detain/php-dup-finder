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

    private function config(bool $poisoned): Config
    {
        return new Config(
            paths: [$this->tmpDir],
            exclude: [],
            minBlockSize: 1,
            // 'aggressive' is the only mode where CanonicalizingVisitor reaches
            // the plugin pass (strict/default early-return before it).
            normalizationPlugins: $poisoned ? [SecondCallSitePoisonPlugin::class] : [],
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
