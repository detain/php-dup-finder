<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Normalization\NormalizationPlugin;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Symfony\Component\Console\Output\NullOutput;

final class PreprocessStageTest extends TestCase
{
    public function testPerFileBlocksNotInSource(): void
    {
        $result = shell_exec('grep -r \'perFileBlocks\' ' . escapeshellarg(__DIR__ . '/../../../src') . ' 2>/dev/null');
        $this->assertSame('', (string)$result, 'perFileBlocks should not exist in src/ - M3 optimization removed the double-copy accumulator');
    }

    public function testProducesBlocksFromFixtureFiles(): void
    {
        $state = $this->scannedState(__DIR__ . '/../../../Fixtures/sql');

        (new PreprocessStage(useCache: false))->run($state, new NullOutput());

        $this->assertNotEmpty($state->blocks, 'sql fixture should produce duplicate-eligible blocks');
        foreach ($state->blocks as $b) {
            $this->assertNotSame('', $b->id, 'each block must be assigned an id');
        }
        $this->assertGreaterThan(0.0, $state->timings['preprocess']);
        $this->assertSame(0, $state->parseErrors);
    }

    public function testEmptyFileListIsNoOp(): void
    {
        $state = new PipelineState(Config::defaults(['/nonexistent/path/that/does/not/exist']));
        $state->files = [];

        (new PreprocessStage(useCache: false))->run($state, new NullOutput());

        $this->assertSame([], $state->blocks);
        $this->assertSame(0, $state->parseErrors);
        $this->assertSame(0, $state->processedFiles);
    }

    public function testCacheAndNoCacheProduceSameBlocks(): void
    {
        $tmpDir = $this->createTempFixture();
        try {
            $config = new Config(
                paths: [$tmpDir],
                exclude: [],
                minBlockSize: 1,
                incremental: true,
                cacheDir: $tmpDir . '/cache',
            );
            $state = new PipelineState($config);
            (new ScanningStage())->run($state, new NullOutput());

            (new PreprocessStage(useCache: false))->run($state, new NullOutput());
            $withoutCache = $state->blocks;

            $state2 = new PipelineState($config);
            (new ScanningStage())->run($state2, new NullOutput());

            (new PreprocessStage(useCache: true))->run($state2, new NullOutput());
            $withCache = $state2->blocks;

            $this->assertCount(count($withoutCache), $withCache, 'block count must match');
            foreach ($withoutCache as $i => $b) {
                $this->assertSame($b->structuralHash, $withCache[$i]->structuralHash, "block $i structuralHash must match");
                $this->assertSame($b->file, $withCache[$i]->file, "block $i file must match");
                $this->assertSame($b->kind, $withCache[$i]->kind, "block $i kind must match");
            }
        } finally {
            $this->removeDirectory($tmpDir);
        }
    }

    public function testCacheReuseSetsReusedFilesCount(): void
    {
        $tmpDir = $this->createTempFixture();
        try {
            $config = new Config(
                paths: [$tmpDir],
                exclude: [],
                minBlockSize: 1,
                incremental: true,
                cacheDir: $tmpDir . '/cache',
            );

            $state = new PipelineState($config);
            (new ScanningStage())->run($state, new NullOutput());
            (new PreprocessStage(useCache: true))->run($state, new NullOutput());
            $this->assertSame(0, $state->reusedFiles, 'first run: nothing to reuse');

            $state2 = new PipelineState($config);
            (new ScanningStage())->run($state2, new NullOutput());
            (new PreprocessStage(useCache: true))->run($state2, new NullOutput());
            $this->assertGreaterThan(0, $state2->reusedFiles, 'second run: files must be reused from cache');
        } finally {
            $this->removeDirectory($tmpDir);
        }
    }

    /**
     * 0.6 balance law: every scanned file lands in exactly ONE bucket and
     * the exposed counters sum back to the scan. Before this fix the
     * zero-block file was invisible (it emitted no row, so 'processed'
     * counted only files that happened to emit rows while scanned files
     * evaporated) and the parse-error file was double-counted (its error
     * row still entered $processedFilesSet).
     */
    public function testCountersBalanceAcrossAllBuckets(): void
    {
        $tmpDir = $this->createMixedFixture();
        try {
            $config = new Config(
                paths: [$tmpDir],
                exclude: [],
                minBlockSize: 1,
                cacheDir: $tmpDir . '/cache',
            );
            $state = new PipelineState($config);
            (new ScanningStage())->run($state, new NullOutput());
            $output = new \Symfony\Component\Console\Output\BufferedOutput();

            (new PreprocessStage(useCache: false))->run($state, $output);

            $this->assertSame(2, $state->processedFiles, 'block-bearing files count as processed');
            $this->assertSame(1, $state->skippedFiles, 'the zero-block file is accounted, not vanished');
            $this->assertSame(1, $state->parseErrors);
            $this->assertSame(0, $state->reusedFiles);
            $this->assertSame(
                count($state->files),
                $state->reusedFiles + $state->processedFiles + $state->skippedFiles + $state->parseErrors,
                'scanned == reused + processed + no-blocks + parse-errors',
            );
            // The one-line summary labels every bucket (a silent `6`
            // vanishing between 156-scanned and 150-processed is exactly
            // what users could not debug).
            $this->assertStringContainsString(
                '4 files (0 reused · 2 processed · 1 no blocks · 1 parse errors)',
                $output->fetch(),
            );
        } finally {
            $this->removeDirectory($tmpDir);
        }
    }

    /**
     * 0.6 + cache interplay: zero-block and errored files are never
     * cache-saved (nothing safe to persist), so a warm run still recomputes
     * them and the balance law holds bucket-for-bucket on reuse.
     */
    public function testWarmRunKeepsBalanceWithSkippedAndErroredFiles(): void
    {
        $tmpDir = $this->createMixedFixture();
        try {
            $config = new Config(
                paths: [$tmpDir],
                exclude: [],
                minBlockSize: 1,
                incremental: true,
                cacheDir: $tmpDir . '/cache',
            );

            $state = new PipelineState($config);
            (new ScanningStage())->run($state, new NullOutput());
            (new PreprocessStage(useCache: true))->run($state, new NullOutput());

            $state2 = new PipelineState($config);
            (new ScanningStage())->run($state2, new NullOutput());
            (new PreprocessStage(useCache: true))->run($state2, new NullOutput());

            $this->assertSame(2, $state2->reusedFiles, 'block files come from cache on the warm run');
            $this->assertSame(0, $state2->processedFiles);
            $this->assertSame(1, $state2->skippedFiles, 'the never-cached zero-block file is recomputed and re-counted');
            $this->assertSame(1, $state2->parseErrors);
            $this->assertSame(
                count($state2->files),
                $state2->reusedFiles + $state2->processedFiles + $state2->skippedFiles + $state2->parseErrors,
                'balance law must survive a cache-warm run',
            );
        } finally {
            $this->removeDirectory($tmpDir);
        }
    }

    /**
     * ui-grammar: the headline on a lone-file run reads "1 file", never
     * "1 files" (same CountPhrase rule as the p0c-m2 report notes —
     * prose surfaces singularize at exactly one).
     */
    public function testHeadlineSingularizesSingleFile(): void
    {
        $tmpDir = sys_get_temp_dir() . '/phpdup_one_' . uniqid();
        @mkdir($tmpDir . '/cache', 0o775, true);
        file_put_contents($tmpDir . '/only.php', '<?php
function lone() { $x = 1; return $x + 2; }
');
        try {
            $config = new Config(paths: [$tmpDir], exclude: [], minBlockSize: 1, cacheDir: $tmpDir . '/cache');
            $state = new PipelineState($config);
            (new ScanningStage())->run($state, new NullOutput());
            $output = new \Symfony\Component\Console\Output\BufferedOutput();

            (new PreprocessStage(useCache: false))->run($state, $output);

            $rendered = $output->fetch();
            $this->assertStringContainsString(
                'phpdup 1 file (0 reused · 1 processed · 0 no blocks · 0 parse errors)',
                $rendered,
            );
            $this->assertStringNotContainsString('1 files', $rendered);
        } finally {
            $this->removeDirectory($tmpDir);
        }
    }

    /**
     * shell-008 arrival-order pin, stage level. The streaming task must be a
     * GENERATOR: with workers=1 the runStreaming serial fallback runs the
     * task in-process (`yield from $task($items)`), so a counting plugin can
     * witness exactly how many files were preprocessed when the stage hits
     * its first YIELD_EVERY (32) boundary.
     *
     * With the fix: the first repaint happens after 32 rows have been
     * pulled one-by-one — file 33 has not even been parsed. Pre-fix the
     * array-returning task ran the WHOLE 40-file batch to completion before
     * row 1 existed, so the probe would read 40 here (40 > 32 is the exact
     * failure mode the finding names: repaint granularity degenerated to
     * "whole chunk", peak child RSS of a whole chunk).
     */
    public function testStreamingTaskRepaintsBeforeTheChunkCompletes(): void
    {
        $tmpDir = sys_get_temp_dir() . '/phpdup_stream_' . uniqid();
        @mkdir($tmpDir . '/cache', 0o775, true);
        $files = [];
        for ($i = 1; $i <= 40; ++$i) {
            $path = sprintf('%s/f%02d.php', $tmpDir, $i);
            file_put_contents($path, "<?php\nfunction fn_{$i}() { site_{$i}(); }\n");
            $files[] = $path;
        }
        try {
            PreprocessStreamingVisitPlugin::$visits = 0;
            $config = new Config(
                paths: [$tmpDir],
                exclude: [],
                minBlockSize: 1,
                workers: 1, // serial in-parent fallback: plugin state stays observable
                normalizationPlugins: [PreprocessStreamingVisitPlugin::class],
                cacheDir: $tmpDir . '/cache',
            );
            $state = new PipelineState($config);
            $state->files = $files; // fixed order, scanner not involved

            $gen = (new PreprocessStage(useCache: false))->iter($state, new NullOutput());
            foreach ($gen as $_) {
                break; // first YIELD_EVERY boundary
            }

            $this->assertSame(32, PreprocessStreamingVisitPlugin::$visits,
                'first repaint after 32 streamed rows, with the last 8 files still untouched');
            $this->assertSame(32, $state->processedFiles);

            foreach ($gen as $_) {
                // drain to stage completion
            }

            $this->assertSame(40, PreprocessStreamingVisitPlugin::$visits, 'the whole chunk is only complete after the drain');
            $this->assertCount(40, $state->blocks);
            $this->assertSame(40, $state->processedFiles);
            $this->assertSame(0, $state->parseErrors);
            $this->assertSame(0, $state->skippedFiles);
        } finally {
            PreprocessStreamingVisitPlugin::$visits = 0;
            $this->removeDirectory($tmpDir);
        }
    }

    /** Two block files, one zero-block file, one unparseable file. */
    private function createMixedFixture(): string
    {
        $tmpDir = sys_get_temp_dir() . '/phpdup_mixed_' . uniqid();
        @mkdir($tmpDir . '/cache', 0o775, true);
        file_put_contents($tmpDir . '/a.php', '<?php
function foo() { return 1; }
function bar() { return 2; }
');
        file_put_contents($tmpDir . '/b.php', '<?php
function baz() { return 3; }
function qux() { return 4; }
');
        file_put_contents($tmpDir . '/empty.php', "<?php\n// parses, extracts nothing\n");
        file_put_contents($tmpDir . '/broken.php', "<?php\nfunction broken( { return 1; }\n");
        return $tmpDir;
    }

    private function createTempFixture(): string
    {
        $tmpDir = sys_get_temp_dir() . '/phpdup_test_' . uniqid();
        @mkdir($tmpDir . '/cache', 0o775, true);
        file_put_contents($tmpDir . '/a.php', '<?php
function foo() { return 1; }
function bar() { return 2; }
');
        file_put_contents($tmpDir . '/b.php', '<?php
function baz() { return 3; }
function qux() { return 4; }
');
        return $tmpDir;
    }

    private function removeDirectory(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testReportsName(): void
    {
        $this->assertSame(
            Stage::Preprocessing,
            (new PreprocessStage(useCache: false))->name(),
        );
    }

    private function scannedState(string $path): PipelineState
    {
        $state = new PipelineState(Config::defaults([$path]));
        (new ScanningStage())->run($state, new NullOutput());
        return $state;
    }
}

/**
 * shell-008 witness for the stage-level pin: one static counter incremented
 * per FuncCall site the aggressive-mode plugin pass is shown. Deterministic
 * because the serial in-parent pool runs exactly one worker registry over
 * the batch in list order.
 */
final class PreprocessStreamingVisitPlugin implements NormalizationPlugin
{
    public static int $visits = 0;

    public function visit(Node $node, string $mode): void
    {
        if ($node instanceof Node\Expr\FuncCall) {
            ++self::$visits;
        }
    }
}
