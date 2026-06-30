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
 * Returns a list of arrays (not Block objects) so the master can
 * deduplicate IDs after collecting batches from all workers.
 *
 * @phpstan-type PreprocessedRow array{
 *   block: Block,
 *   parse_error: ?string,
 *   skipped: bool,
 *   file: string,
 * }
 */
final class PreprocessWorker
{
    public function __construct(private readonly Config $config)
    {
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
     * @param list<string> $files
     * @return list<array{type: 'block'|'error'|'skipped', file: string, block?: Block, message?: string}>
     */
    public function process(array $files, ?OutputInterface $output = null): array
    {
        if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln(sprintf('worker: processing %d files in batch [%s]', count($files), MemoryDebug::getMemoryUsage()));
        }

        $cache  = new AstCache($this->config->cacheDir);
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
        $lowMemory = $this->config->lowMemory;
        $nodePool  = $lowMemory ? new CanonicalNodePool() : null;
        $toolFor = static function (Config $cfg) use (&$tooling, $pluginRegistry, $lowMemory, $nodePool): array {
            $key = sprintf(
                '%d|%d|%s|%d|%s|%s|%d|%d|%s|%s|%s',
                $cfg->minBlockSize, $cfg->maxBlockSize,
                $cfg->normalizationMode, $cfg->ngramSize,
                implode(',', $cfg->allowedKinds),
                implode(',', $cfg->normalizationPlugins),
                $cfg->dbAware ? 1 : 0,
                $cfg->trinityCollapse ? 1 : 0,
                self::serializeStringMap($cfg->dbSymbolsMethods),
                self::serializeStringMap($cfg->dbSymbolsFunctions),
                $cfg->scorer,
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
                    'fp'         => new NgramFingerprint($cfg->ngramSize, $lowMemory),
                    // IR machinery is constructed lazily — only present
                    // when scorer=ir to keep the default path's startup
                    // cost unchanged.
                    'irLifter'   => $cfg->scorer === 'ir' ? new IrLifter($dbRegistry ?? new DbOpRegistry()) : null,
                    'irPrinter'  => $cfg->scorer === 'ir' ? new IrPrinter() : null,
                ];
            }
            return $tooling[$key];
        };

        $out = [];
        $fileIdx = 0;
        foreach ($files as $path) {
            ++$fileIdx;
            if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $output->writeln(sprintf('worker: parsing file %d/%d: %s [%s]', $fileIdx, count($files), $path, MemoryDebug::getMemoryUsage()));
            }
            $stmts = $cache->get($path);
            if ($stmts === null) {
                if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    $output->writeln(sprintf('worker: cache miss, parsing: %s', $path));
                }
                $stmts = $parser->parseFile($path);
                if ($stmts === null) {
                    if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                        $output->writeln(sprintf('worker: parse error for %s: %s', $path, $parser->lastError?->getMessage() ?? 'unknown'));
                    }
                    $out[] = ['type' => 'error', 'file' => $path, 'message' => $parser->lastError?->getMessage() ?? 'parse error'];
                    continue;
                }
                $cache->put($path, $stmts);
            } else {
                if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    $output->writeln(sprintf('worker: cache hit for: %s', $path));
                }
            }
            $cfg   = $this->config->effectiveFor($path);
            $tools = $toolFor($cfg);
            $blockCount = 0;
            foreach ($tools['extractor']->extract($path, $stmts) as $block) {
                ++$blockCount;
                if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
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
                if ($cfg->lazyAst) {
                    $block->unloadAst();
                }
                $out[] = ['type' => 'block', 'file' => $path, 'block' => $block];
            }
            if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                if ($blockCount === 0) {
                    $output->writeln(sprintf('worker:   no blocks extracted from %s (kind=%s, minBlockSize=%d)', $path, $cfg->allowedKinds === [] ? 'all' : implode(',', $cfg->allowedKinds), $cfg->minBlockSize));
                } else {
                    $output->writeln(sprintf('worker:   extracted %d blocks from %s', $blockCount, $path));
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
        if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $blockRows = array_filter($out, static fn(array $row): bool => $row['type'] === 'block');
            $blockCount = count($blockRows);
            $fileCount = count(array_unique(array_map(static fn(array $row): string => $row['file'], $blockRows)));
            $output->writeln(sprintf('worker: normalized %d blocks from %d files [%s]', $blockCount, $fileCount, MemoryDebug::getMemoryUsage()));
        }
        return $out;
    }
}
