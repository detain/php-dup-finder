<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Parallel;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Extraction\Block;
use Phpdup\Normalization\NormalizationPlugin;
use Phpdup\Parallel\PreprocessWorker;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Symfony\Component\Console\Output\NullOutput;

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
 *
 * And the engine-019 cache-policy thread: with astDiskCache disabled (what
 * PreprocessStage passes for --no-cache) the worker neither writes parser
 * blobs nor serves planted ones — fresh parses only, cache dir byte-still.
 *
 * And shell-027: low_memory is read from the EFFECTIVE per-file config in
 * the tooling closure (per-dir subtrees really fingerprint compact), and
 * the flag rides the IndexStore config key (a --low-memory warmed cache
 * never replays into a default run).
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

    /**
     * engine-019 acceptance, arm A: a worker with astDiskCache disabled
     * completes extraction + hashing + fingerprinting from fresh parses
     * (rows carry live structuralHash/ngramBag values) and leaves the
     * cache dir byte-empty — while the SAME batch through an enabled
     * worker (the historic default) does populate it. Without the control
     * the empty-dir assert would be vacuous: it would only prove that
     * nobody in this test ever reaches the write path.
     */
    public function testDisabledAstDiskCacheParsesFreshAndWritesNoBlobs(): void
    {
        $path     = $this->writePhp('nocache_fresh.php', "<?php\nfunction alpha() { one(); }\nfunction beta() { two(); }\n");
        $cacheDir = $this->tmpDir . '/cache';

        $rows = (new PreprocessWorker($this->config(poisoned: false), astDiskCache: false))->process([$path]);

        $this->assertCount(2, $rows, 'both blocks still come out — fingerprinting completes from fresh parses');
        foreach ($rows as $row) {
            $this->assertSame('block', $row['type']);
            /** @var Block $block */
            $block = $row['block'];
            $this->assertSame($path, $block->file);
            $this->assertNotSame('', $block->structuralHash, 'block must carry a live structural hash');
            $this->assertNotEmpty($block->ngramBag, 'block must carry a live n-gram bag');
        }
        $this->assertSame([], $this->snapshotCacheDir($cacheDir), 'a disabled run must not create a single cache file');

        // Control arm: default (enabled) worker on the same file writes blobs.
        $control = (new PreprocessWorker($this->config(poisoned: false)))->process([$path]);
        $this->assertCount(2, $control);
        $this->assertNotSame([], $this->snapshotCacheDir($cacheDir), 'the enabled default must still populate the parser cache');
    }

    /**
     * engine-019 acceptance, arm B (SEC-02 mirror, worker level): a forged
     * parser-v6 blob planted for a real file via the legitimate cache
     * writer (framing-valid, allow-listed classes — exactly the poisoning
     * shape) IS served to an enabled worker (control: the hazard is real
     * and observable through block names) but never touches a disabled
     * run: fresh-parse rows, and the decoy blob byte-untouched afterwards
     * (never consumed, never rewritten).
     */
    public function testPlantedDecoyServedWhenEnabledNeverServedWhenDisabled(): void
    {
        $path = $this->writePhp('decoy_target.php', "<?php\nfunction real_alpha() { one(); }\n");

        $parser = new AstParser();
        $forged = $parser->parseCode("<?php\nfunction forged_beta() { two(); }\n");
        $this->assertIsArray($forged);
        (new AstCache($this->tmpDir . '/cache'))->put($path, $forged);

        $cacheDir = $this->tmpDir . '/cache';
        $planted  = $this->snapshotCacheDir($cacheDir);
        $this->assertCount(1, $planted, 'the decoy blob must be in place before the runs');

        // Control: with the parser cache live, the poisoned blob decides
        // what the worker fingerprints — this is the escape hatch breaking.
        $served = (new PreprocessWorker($this->config(poisoned: false)))->process([$path]);
        $this->assertSame(
            ['forged_beta'],
            array_map(static fn (array $r): string => (string) $r['block']->name, $served),
            'the enabled run proves the decoy is served (control, not desired behaviour)',
        );

        // Fixed path: --no-cache semantics (astDiskCache: false) refuse it.
        $fresh = (new PreprocessWorker($this->config(poisoned: false), astDiskCache: false))->process([$path]);
        $this->assertSame(
            ['real_alpha'],
            array_map(static fn (array $r): string => (string) $r['block']->name, $fresh),
            'the disabled run must fingerprint the fresh parse, never the planted AST',
        );
        $this->assertSame($planted, $this->snapshotCacheDir($cacheDir), 'decoy blob neither consumed nor rewritten by the disabled run');
    }

    /**
     * engine-019 end-to-end mirror at the stage seam: a PreprocessStage
     * built with useCache: false — exactly what Command.php threads for
     * --no-cache — must drive its (possibly forked) workers to ignore the
     * planted decoy: the real block reaches $state->blocks, the forged
     * name never does, and the cache dir is byte-still after the run.
     */
    public function testStageWithUseCacheFalseNeverServesPlantedDecoy(): void
    {
        $path = $this->writePhp('stage_decoy_target.php', "<?php\nfunction stage_real() { one(); }\n");

        $parser = new AstParser();
        $forged = $parser->parseCode("<?php\nfunction stage_forged() { two(); }\n");
        $this->assertIsArray($forged);
        $cacheDir = $this->tmpDir . '/cache';
        (new AstCache($cacheDir))->put($path, $forged);
        $before = $this->snapshotCacheDir($cacheDir);
        $this->assertCount(1, $before, 'the decoy blob must be in place before the stage run');

        $config = new Config(
            paths: [$this->tmpDir],
            exclude: [],
            minBlockSize: 1,
            cacheDir: $cacheDir,
        );
        $state = new PipelineState($config);
        $state->files = [$path];

        (new PreprocessStage(useCache: false))->run($state, new NullOutput());

        $this->assertSame(0, $state->parseErrors);
        $names = array_map(static fn (Block $b): string => (string) $b->name, $state->blocks);
        $this->assertSame(['stage_real'], $names, 'the stage must fingerprint the fresh parse, never the planted decoy');
        $this->assertSame($before, $this->snapshotCacheDir($cacheDir), 'cache dir byte-unchanged through a --no-cache stage run');
    }

    /**
     * shell-027 acceptance, arm 1 (per-dir capture): a per-directory
     * `.phpdup.json`-style override with low_memory:true on subdir b/ only
     * must make b/ files fingerprint with the CompactNgramBag int-keyed
     * form while a/ files keep the 64-bit-hex string keys — inside ONE
     * worker run, which is only possible if the tooling closure reads
     * lowMemory from the effective per-file config (pre-fix it captured
     * the ROOT's false and the override was a silent no-op: both files
     * came out string-keyed and the b/ assertion below is RED).
     * Bag COUNTS match across the two forms: compact() only re-renders
     * the digest, so the knob must not change what is bagged.
     */
    public function testPerDirectoryLowMemoryCompactsOnlyThatSubtree(): void
    {
        mkdir($this->tmpDir . '/a', 0o775, true);
        mkdir($this->tmpDir . '/b', 0o775, true);
        $src = "<?php\nfunction alpha(\$v) { return helper(\$v) + 1; }\nfunction beta(\$v) { return other(\$v) + 2; }\n";
        $pathA = $this->tmpDir . '/a/lowmem_a.php';
        $pathB = $this->tmpDir . '/b/lowmem_b.php';
        file_put_contents($pathA, $src);
        file_put_contents($pathB, $src);

        $config = new Config(
            paths: [$this->tmpDir],
            exclude: [],
            minBlockSize: 1,
            cacheDir: $this->tmpDir . '/cache',
            perDirectoryOverrides: [$this->tmpDir . '/b' => ['low_memory' => true]],
        );
        $rows = (new PreprocessWorker($config))->process([$pathA, $pathB]);

        $blocksByFile = [];
        foreach ($rows as $row) {
            if ($row['type'] === 'block') {
                $blocksByFile[$row['file']][] = $row['block'];
            }
        }
        $this->assertCount(2, $blocksByFile[$pathA] ?? [], 'dir A extracted both functions');
        $this->assertCount(2, $blocksByFile[$pathB] ?? [], 'dir B extracted both functions');

        foreach ($blocksByFile[$pathA] as $i => $block) {
            $keys = array_keys($block->ngramBag);
            $this->assertNotEmpty($keys, "A block $i must carry a bag");
            foreach ($keys as $k) {
                $this->assertIsString($k, "A (root low_memory:false) bag keys must stay hex strings, got int $k");
            }
        }
        foreach ($blocksByFile[$pathB] as $i => $block) {
            $keys = array_keys($block->ngramBag);
            $this->assertNotEmpty($keys, "B block $i must carry a bag");
            foreach ($keys as $k) {
                $this->assertIsInt($k, "B (per-dir low_memory:true) bag keys must be compact ints, got string $k");
                $this->assertGreaterThanOrEqual(0, $k, 'compact() clears the sign bit — keys are positive');
            }
        }
        $this->assertCount(
            count(array_keys($blocksByFile[$pathA][0]->ngramBag)),
            array_keys($blocksByFile[$pathB][0]->ngramBag),
            'compact is a re-render of the SAME grams — per-dir low_memory must not change bag cardinality',
        );
    }

    /**
     * shell-027 acceptance, arm 2 (end-to-end cache refusal): a corpus
     * indexed under low_memory:true (what --low-memory drives) then
     * re-run without it must MISS the on-disk snapshots — the flag rides
     * the IndexStore config key. The same-flag control run in between
     * proves the refusal is about the flag alone, not about the cache
     * being broken in this environment (non-vacuity), and the served /
     * recomputed bag key shapes name the misfeed the fix refuses.
     */
    public function testStageRefusesLowMemoryWarmedCacheOnDefaultRerun(): void
    {
        $path     = $this->writePhp('lowmem_corpus.php', "<?php\nfunction one() { x(); y(); z(); }\n");
        $cacheDir = $this->tmpDir . '/cache';

        $mk = fn (bool $lowMemory): Config => new Config(
            paths: [$this->tmpDir],
            exclude: [],
            minBlockSize: 1,
            cacheDir: $cacheDir,
            lowMemory: $lowMemory,
        );

        // Cold run under --low-memory: processes, saves the snapshot.
        $s1 = new PipelineState($mk(true));
        $s1->files = [$path];
        (new PreprocessStage(useCache: true))->run($s1, new NullOutput());
        $this->assertSame(1, $s1->processedFiles, 'precondition: the cold run processes the file');
        $this->assertFileExists($cacheDir . '/' . sha1($path) . '.idx',
            'precondition: the run persisted the per-file IndexStore snapshot');

        // Control: same flags → genuine HIT, serving the compact bags.
        $s2 = new PipelineState($mk(true));
        $s2->files = [$path];
        (new PreprocessStage(useCache: true))->run($s2, new NullOutput());
        $this->assertSame(1, $s2->reusedFiles, 'control: the same-flag rerun must hit the cache');
        foreach ($s2->blocks as $block) {
            foreach (array_keys($block->ngramBag) as $k) {
                $this->assertIsInt($k, 'the hit snapshot must replay compact (int-keyed) bags');
            }
        }

        // Acceptance: default run must NOT replay the compact snapshots.
        // Pre-fix this reused the warm cache (reusedFiles === 1) and the
        // blocks carried int keys — compact bags poisoning a 64-bit run.
        $s3 = new PipelineState($mk(false));
        $s3->files = [$path];
        (new PreprocessStage(useCache: true))->run($s3, new NullOutput());
        $this->assertSame(0, $s3->reusedFiles, 'a low-memory warmed cache must never replay into a default run');
        $this->assertSame(1, $s3->processedFiles, 'the file is reprocessed instead');
        $this->assertNotEmpty($s3->blocks, 'non-vacuity: the file does yield blocks');
        foreach ($s3->blocks as $block) {
            foreach (array_keys($block->ngramBag) as $k) {
                $this->assertIsString($k, 'the recompute must mint default (hex-string) bag keys');
            }
        }
    }

    /**
     * Recursive name => content-sha1 census of a directory ('' map when the
     * dir does not exist). Used as the byte-identity witness for the
     * engine-019 cache-refusal pins.
     *
     * @return array<string,string>
     */
    private function snapshotCacheDir(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($walk as $entry) {
            /** @var \SplFileInfo $entry */
            $rel = substr($entry->getPathname(), strlen($dir));
            $out[$rel] = $entry->isDir() ? '<dir>' : (sha1_file($entry->getPathname()) ?: '<unreadable>');
        }
        ksort($out);
        return $out;
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
