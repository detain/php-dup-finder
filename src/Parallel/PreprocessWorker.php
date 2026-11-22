<?php
declare(strict_types=1);

namespace Phpdup\Parallel;

use Phpdup\Cli\Config;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Ir\IrLifter;
use Phpdup\Ir\IrPrinter;
use Phpdup\Normalization\DbOpRegistry;
use Phpdup\Normalization\Normalizer;
use Phpdup\Normalization\PluginRegistry;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use Phpdup\Util\MemoryDebug;
use Phpdup\Util\CanonicalNodePool;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Per-worker preprocessing routine: parse → extract → normalize →
 * hash → ngram-fingerprint for a batch of files. Stateless; safe to
 * run inside a forked child.
 *
 * Two entry points, same row stream:
 *  - {@see processStream()} — the generator twin (shell-008): yields each
 *    file's rows the moment that file completes, so a forked child streams
 *    records over the socketpair progressively instead of materializing
 *    the whole chunk's Block graph before the first write.
 *  - {@see process()} — thin drain over the twin, kept as the array API
 *    for serial callers (only the unit pins consume it now; the streaming
 *    stage task calls the generator directly).
 *
 * Returns a list of arrays (not Block objects) so the master can
 * deduplicate IDs after collecting batches from all workers.
 *
 * @phpstan-type PreprocessedRow array{type: 'block', file: string, block: Block}
 *   |array{type: 'error', file: string, message: string}
 *   |array{type: 'skipped', file: string, blocks: 0}
 */
final class PreprocessWorker
{
    /**
     * @param bool $astDiskCache The run's cache policy for the on-disk AST
     *        cache (engine-019). PreprocessStage threads its useCache flag
     *        (--no-cache clears it); false collapses the worker's AstCache
     *        to its disabled ''-dir no-op form so the run neither writes
     *        parser blobs nor serves pre-planted ones. Defaults to true so
     *        every array-API caller keeps the historic behaviour.
     */
    public function __construct(
        private readonly Config $config,
        private readonly bool $astDiskCache = true,
    ) {
    }

    /**
     * Order-stable serialisation of a string→string map for use in
     * tooling-cache keys. Sorting by key keeps the hash deterministic
     * across phpdup runs even when the user's `db_symbols` map is
     * authored in a different order.
     *
     * @param array<string,string> $map
     */
    private static function serializeStringMap(array $map): string
    {
        ksort($map);
        $out = '';
        foreach ($map as $k => $v) {
            $out .= $k . '=' . $v . ',';
        }
        return $out;
    }

    /**
     * Fold a flat token list into a `token → count` multiset.
     *
     * Used by the option-5 IR-tier preprocessing path to produce a
     * shape compatible with {@see \Phpdup\Similarity\JaccardSimilarity}.
     *
     * @param list<string> $tokens
     * @return array<string,int>
     */
    private static function tokenMultiset(array $tokens): array
    {
        $bag = [];
        foreach ($tokens as $t) {
            $bag[$t] = ($bag[$t] ?? 0) + 1;
        }
        return $bag;
    }

    /**
     * Array API (serial callers): drain the whole batch into a list.
     *
     * Content-identical to collecting {@see processStream()} — this is only
     * the eager wrapper; the streaming behaviour lives in the twin.
     *
     * @param list<string> $files
     * @return list<array{type: 'block'|'error'|'skipped', file: string, block?: Block, message?: string, blocks?: 0}>
     */
    public function process(array $files, ?OutputInterface $output = null): array
    {
        $out = [];
        foreach ($this->processStream($files, $output) as $row) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Streaming twin (shell-008): yield each file's rows as soon as that
     * file completes.
     *
     * The fork-zone contract that makes this matter: WorkerPool::runStreaming
     * drives the child task with `foreach ($task($chunk) as $record)` and
     * writes every yielded record to the socketpair immediately. An
     * array-returning task therefore forces the child to materialize the
     * whole chunk (every Block with its canonical AST) before the parent
     * observes ANY record — peak child RSS of one full chunk and repaint
     * stalls at chunk granularity. A generator task streams per file:
     * first record observable while the chunk's later files are still
     * untouched. The pair-score path already proved this shape
     * (ClusterStage::scorePairs task).
     *
     * Buffer-and-discard is preserved at FILE granularity: rows are staged
     * in $fileRows and only yielded once the WHOLE file completed, so a
     * mid-file throw still never leaks partial rows for that file.
     *
     * @param list<string> $files
     * @return \Generator<int, array{type: 'block'|'error'|'skipped', file: string, block?: Block, message?: string, blocks?: 0}>
     */
    public function processStream(array $files, ?OutputInterface $output = null): \Generator
    {
        $debug = $output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG;
        if ($debug) {
            $output->writeln(sprintf('worker: processing %d files in batch [%s]', count($files), MemoryDebug::getMemoryUsage()));
        }

        // engine-019: honour the run's cache policy here, not just at the
        // stage's IndexStore gate. Historically this line passed
        // config->cacheDir unconditionally, so a --no-cache run still wrote
        // fresh parser blobs AND still fingerprinted from pre-planted ones
        // (SEC-02's escape hatch). '' makes AstCache::isEnabled() false, so
        // get()/put() no-op and every file parses fresh — the same policy
        // shape RefactorStage applies to its BlockAstLoader's AstCache.
        $cache  = new AstCache($this->astDiskCache ? $this->config->cacheDir : '');
        $parser = new AstParser();
        $hasher = new SubtreeHasher();

        // Cache per-config tooling so paths sharing the same effective
        // config don't pay reconstruction cost. Keyed by a small fingerprint
        // of the fields the extractor / normalizer / fingerprinter read.
        $tooling = [];
        // Build the plugin registry once per worker (plugin classes are
        // typically cheap to instantiate; we pass the same registry into
        // every Normalizer below).
        $pluginRegistry = $this->config->normalizationPlugins !== []
            ? PluginRegistry::fromClassNames($this->config->normalizationPlugins)
            : null;
        // shell-027: the canonical-node pool is per-WORKER shared (its whole
        // point is corpus-wide dedup), but whether it exists at all is now
        // decided by the EFFECTIVE config, not the root's — a per-directory
        // low_memory:true must get pool-backed normalization for its subtree.
        // Lazily minted inside $toolFor on the first lowMemory entry.
        $nodePool = null;
        $toolFor = static function (Config $cfg) use (&$tooling, $pluginRegistry, &$nodePool): array {
            $key = sprintf(
                '%d|%d|%s|%d|%s|%s|%d|%d|%s|%s|%s|%s|%d',
                $cfg->minBlockSize, $cfg->maxBlockSize,
                $cfg->normalizationMode, $cfg->ngramSize,
                implode(',', $cfg->allowedKinds),
                implode(',', $cfg->normalizationPlugins),
                $cfg->dbAware ? 1 : 0,
                $cfg->trinityCollapse ? 1 : 0,
                self::serializeStringMap($cfg->dbSymbolsMethods),
                self::serializeStringMap($cfg->dbSymbolsFunctions),
                $cfg->scorer,
                // Item 1.1 (§5a): the mode rewrites every n-gram key, so
                // two modes must never share one tooling entry inside a
                // worker. Belt + braces: the same value also rides the
                // IndexStore and ClusterCache config keys (cross-process
                // misfeed refusal) — one forgotten key = silently
                // mixed-mode bags in a warm cache.
                $cfg->fingerprintIdMode,
                // shell-027: exactly the Item-1.1 argument one knob over.
                // low_memory swaps the bag to CompactNgramBag's int-keyed
                // 63-bit form (and Normalizer's node-pool path), so a
                // per-directory low_memory subtree must never share one
                // tooling entry with the root's default. Key-namespace
                // route only: no PARSER_VERSION bump (fingerprintIdMode
                // precedent).
                $cfg->lowMemory ? 1 : 0,
            );
            if (!isset($tooling[$key])) {
                // Build the DbOpRegistry with the user's symbol overlay
                // once per config-key so every block in this worker
                // batch shares the same lookup tables.
                $dbRegistry = ($cfg->dbSymbolsMethods === [] && $cfg->dbSymbolsFunctions === [])
                    ? null
                    : new DbOpRegistry(
                        customMethodOps: $cfg->dbSymbolsMethods,
                        customFunctionOps: $cfg->dbSymbolsFunctions,
                    );
                // shell-027: low_memory is read from the EFFECTIVE per-file
                // config, never the root capture the old closure made —
                // that capture is the bug that made per-dir low_memory a
                // silent no-op in the fork zone. The pool itself stays one
                // per worker (dedup is corpus-wide): minted on the first
                // lowMemory entry, shared by every later one.
                $lowMemory = $cfg->lowMemory;
                if ($lowMemory && $nodePool === null) {
                    $nodePool = new CanonicalNodePool();
                }
                $tooling[$key] = [
                    'extractor'  => new BlockExtractor($cfg->minBlockSize, $cfg->maxBlockSize, $cfg->allowedKinds),
                    'normalizer' => new Normalizer(
                        mode: $cfg->normalizationMode,
                        plugins: $pluginRegistry,
                        dbAware: $cfg->dbAware,
                        dbOpRegistry: $dbRegistry,
                        trinityCollapse: $cfg->trinityCollapse,
                        lowMemory: $lowMemory,
                        nodePool: $nodePool,
                    ),
                    'fp'         => new NgramFingerprint($cfg->ngramSize, $lowMemory, $cfg->fingerprintIdsUnnumbered()),
                    // IR machinery is constructed lazily — only present
                    // when scorer=ir to keep the default path's startup
                    // cost unchanged.
                    'irLifter'   => $cfg->scorer === 'ir' ? new IrLifter($dbRegistry ?? new DbOpRegistry()) : null,
                    'irPrinter'  => $cfg->scorer === 'ir' ? new IrPrinter() : null,
                ];
            }
            return $tooling[$key];
        };

        $fileIdx = 0;
        // Debug-run totals for the closing summary line. Tracked only when
        // DEBUG: the generator cannot materialize $out anymore, so the old
        // end-of-run array_filter over every row becomes these counters.
        $debugBlockRows  = 0;
        $debugBlockFiles = [];
        foreach ($files as $path) {
            ++$fileIdx;
            if ($debug) {
                $output->writeln(sprintf('worker: parsing file %d/%d: %s [%s]', $fileIdx, count($files), $path, MemoryDebug::getMemoryUsage()));
            }
            $stmts = $cache->get($path);
            if ($stmts === null) {
                if ($debug) {
                    $output->writeln(sprintf('worker: cache miss, parsing: %s', $path));
                }
                $stmts = $parser->parseFile($path);
                if ($stmts === null) {
                    if ($debug) {
                        $output->writeln(sprintf('worker: parse error for %s: %s', $path, $parser->lastError?->getMessage() ?? 'unknown'));
                    }
                    yield ['type' => 'error', 'file' => $path, 'message' => $parser->lastError?->getMessage() ?? 'parse error'];
                    continue;
                }
                $cache->put($path, $stmts);
            } else {
                if ($debug) {
                    $output->writeln(sprintf('worker: cache hit for: %s', $path));
                }
            }
            // Buffer-and-discard (Hazard-1): this file's rows are staged in
            // $fileRows and only reach the consumer once the WHOLE file
            // completed. A mid-file throw (unexpected AST shape, broken
            // normalization plugin, ...) must never leave a partial block
            // list in the stream — the master counts processed files by row
            // and caches exactly the rows it receives (PreprocessStage
            // cache-save), so a truncated list would be persisted as the
            // file's complete row set and silently hide the rest of the
            // file on every later incremental run.
            $fileRows   = [];
            $blockCount = 0;
            try {
                $cfg   = $this->config->effectiveFor($path);
                $tools = $toolFor($cfg);
                foreach ($tools['extractor']->extract($path, $stmts) as $block) {
                    ++$blockCount;
                    if ($debug) {
                        $output->writeln(sprintf('worker:   extracted block kind=%s size=%d from %s', $block->kind, $block->size, $path));
                    }
                    $tools['normalizer']->normalize($block);
                    $block->structuralHash = $hasher->hash($block->canonical);
                    $block->ngramBag = $tools['fp']->fingerprint($block->canonical);
                    // Option-5 IR-tier preprocessing: lift the block's
                    // *original* AST (pre-canonicalisation, so library
                    // surface still informs the lifter's pattern matchers)
                    // and store the printed-token multiset on the block.
                    // A null lift result leaves $irBag null, which the
                    // Clusterer treats as "skip the IR tier for this pair".
                    if ($tools['irLifter'] !== null && $block->ast !== null) {
                        $ir = $tools['irLifter']->lift($block->ast);
                        if ($ir !== null) {
                            $block->irBag = self::tokenMultiset($tools['irPrinter']->tokens($ir));
                        }
                    }
                    // Drop the original AST now that canonicalization + all
                    // downstream consumers (hashing, fingerprinting, IR) are
                    // done.  AntiUnifier reloads via BlockAstLoader on demand.
                    // Only unload when lazyAst is enabled; otherwise keep the
                    // AST in memory for RefactorStage (which has no loader when
                    // lazyAst=false).
                    // Fix-review M-1: 'script' folds are exempt — synthetic,
                    // non-relocatable, and their AST is the only copy. This
                    // site is the DEFAULT-run unload (workers always process);
                    // ClusterStage::unloadAsts carries the matching guard for
                    // replayed/serial-sourced blocks.
                    if ($cfg->lazyAst && $block->kind !== 'script') {
                        $block->unloadAst();
                    }
                    $fileRows[] = ['type' => 'block', 'file' => $path, 'block' => $block];
                }
                if ($debug) {
                    if ($blockCount === 0) {
                        $output->writeln(sprintf('worker:   no blocks extracted from %s (kind=%s, minBlockSize=%d)', $path, $cfg->allowedKinds === [] ? 'all' : implode(',', $cfg->allowedKinds), $cfg->minBlockSize));
                    } else {
                        $output->writeln(sprintf('worker:   extracted %d blocks from %s', $blockCount, $path));
                    }
                }
                if ($blockCount === 0) {
                    // 0.6 skipped-file reporting: a file that parsed cleanly
                    // but yielded no comparable blocks used to vanish from
                    // every count. The 'skipped' row shape was pre-declared
                    // in this method's contract (and in PreprocessStage's
                    // consumer) but never emitted until now. Payload is
                    // scalar-only (string path + literal 0) so it rides the
                    // SOCKETPAIR worker wire unchanged. Staged in $fileRows
                    // so the buffer-and-discard rule above still applies: a
                    // file that throws never leaks a row of any type.
                    $fileRows[] = ['type' => 'skipped', 'file' => $path, 'blocks' => 0];
                }
            } catch (\Throwable $e) {
                // Same error-row contract as the parse-error path above:
                // PreprocessStage turns this into parseErrors++ (and now
                // excludes the file from cache-save), so one poison file fails
                // softly instead of taking the whole forked run to exit 255.
                yield ['type' => 'error', 'file' => $path, 'message' => $e->getMessage()];
                continue;
            }
            foreach ($fileRows as $row) {
                yield $row;
                if ($debug && $row['type'] === 'block') {
                    ++$debugBlockRows;
                    $debugBlockFiles[$row['file']] = true;
                }
            }
            // Trigger PHP's cyclic GC and clear per-cycle memory caches
            // every 10 files to prevent memory buildup from serialize/
            // unserialize operations in Normalizer::deepClone().
            if ($fileIdx % 10 === 0) {
                gc_collect_cycles();
                gc_mem_caches();
            }
        }
        if ($debug) {
            $output->writeln(sprintf('worker: normalized %d blocks from %d files [%s]', $debugBlockRows, count($debugBlockFiles), MemoryDebug::getMemoryUsage()));
        }
    }
}
