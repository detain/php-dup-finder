<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Extraction\Block;
use Phpdup\Parallel\PreprocessWorker;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Persistence\IndexStore;
use Phpdup\Pipeline\CooperativeStageInterface;
use Phpdup\Scanning\RepoLabels;
use Phpdup\Pipeline\NullProgressListener;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\ProgressListener;
use Phpdup\Pipeline\Stage;
use Phpdup\Util\CountPhrase;
use Phpdup\Util\MemoryDebug;
use Symfony\Component\Console\Output\OutputInterface;

final class PreprocessStage implements CooperativeStageInterface
{
    /** Yield to the runtime every N records streamed from workers. */
    private const YIELD_EVERY = 32;

    private readonly ProgressListener $listener;

    public function __construct(
        private readonly bool $useCache,
        private readonly bool $showStats = false,
        ?ProgressListener $listener = null,
        private readonly int $maxMemoryMb = 0,
    ) {
        $this->listener = $listener ?? new NullProgressListener();
    }

    private function checkMemory(OutputInterface $output): void
    {
        $rssMb = (int)floor(memory_get_peak_usage(true) / (1024 * 1024));
        // Debug: always emit memory RSS when verbosity is DEBUG.
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln(sprintf('<debug>phpdup</debug> preprocess check: %s</debug>', MemoryDebug::getMemoryUsage()));
        }
        if ($this->maxMemoryMb <= 0) {
            return;
        }
        if ($rssMb > $this->maxMemoryMb && $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $output->writeln(sprintf(
                "<comment>phpdup: peak RSS %d MB exceeded --max-memory=%d. Consider --exact-only or a larger --min-block-size.</comment>",
                $rssMb, $this->maxMemoryMb,
            ));
        }
    }

    #[\Override]
    public function name(): Stage
    {
        return Stage::Preprocessing;
    }

    #[\Override]
    public function run(PipelineState $state, OutputInterface $output): void
    {
        foreach ($this->iter($state, $output) as $_) {
            // synchronous drain
        }
    }

    /**
     * Streaming preprocessing pipeline.
     *
     * Blocks are accumulated in a single $blocks array throughout the run.
     * At cache-save time a per-file map is built from $blocks in one O(n) pass
     * (no separate per-file accumulator), keeping peak block memory at ~1x.
     *
     * @return \Generator<Stage>
     */
    #[\Override]
    public function iter(PipelineState $state, OutputInterface $output): \Generator
    {
        $config = $state->config;
        $files  = $state->files;

        $configKey = sha1(serialize([
            $config->minBlockSize, $config->maxBlockSize,
            $config->normalizationMode, $config->ngramSize,
            // Include dbAware + trinityCollapse + the custom DB
            // symbol maps so caches are invalidated when the user
            // toggles flags or changes the symbol-equivalence
            // registry between runs (the canonical AST is
            // structurally different in each case).
            $config->dbAware,
            $config->trinityCollapse,
            $config->dbSymbolsMethods,
            $config->dbSymbolsFunctions,
            // Scorer mode controls whether IR token bags are
            // pre-computed during preprocessing; flipping it must
            // invalidate so the cached Block records carry (or omit)
            // their irBag consistently with the live config.
            $config->scorer,
            // Item 1.1 (§5a): fingerprint_id_mode rewrites every stored
            // n-gram bag key (normalized ASTs themselves are untouched —
            // the strip happens at bagging time). A numbered cache
            // served to an un-numbered run (or the reverse) would mix
            // bag species inside one candidate index with no error —
            // refuse cross-mode reuse at the key instead. No
            // PARSER_VERSION bump: the mode value in this key already
            // separates the two namespaces byte-exactly.
            $config->fingerprintIdMode,
            // Item 1.3: the extraction KIND SET changes which blocks a
            // file yields at all — 'script' folds exist only for opt-in
            // runs. A cache written without a kind replayed to a run that
            // requested it (or vice-versa) would silently under- or
            // over-report; bind the per-run kind set into the key the
            // same way the mode knobs above are bound. No PARSER_VERSION
            // bump: distinct key values namespace the stores byte-exactly.
            $config->allowedKinds,
        ]));
        $store = ($this->useCache && $config->incremental)
            ? new IndexStore($config->cacheDir, $configKey)
            : null;

        $blocks = [];

        // Phase 2a: split files into reuse (cache hit) and process (need work).
        // Yield every YIELD_EVERY cache lookups so the renderer can repaint while
        // we walk a large file list.
        $toProcess  = [];
        $sinceYield = 0;
        if ($store !== null) {
            $state->debug($output, sprintf('preprocess: checking cache for %d files [%s]', count($files), MemoryDebug::getMemoryUsage()));
            foreach ($files as $f) {
                $cached = $store->load($f);
                if ($cached !== null) {
                    foreach ($cached as $b) {
                        $blocks[] = $b;
                    }
                    $state->reusedFiles++;
                    $this->listener->onFilePreprocessed(
                        $state->processedFiles, $state->reusedFiles, $state->parseErrors,
                    );
                } else {
                    $toProcess[] = $f;
                }
                if (++$sinceYield >= self::YIELD_EVERY) {
                    $sinceYield = 0;
                if ($state->cancelled) {
                    break;
                }
                    $state->sampleMemory();
                    $state->debug($output, sprintf('preprocess: cache check progress %d/%d files [%s]', $state->processedFiles, count($files), MemoryDebug::getMemoryUsage()));
                    yield Stage::Preprocessing;
                }
            }
        } else {
            $toProcess = $files;
        }

        // Phase 2b: process the rest. Streaming WorkerPool yields records as
        // each child produces them; we yield up to the runtime every
        // YIELD_EVERY records so the TUI can repaint mid-stage instead of
        // freezing for the duration of the parallel work.
        $tPre = microtime(true);
        if ($toProcess) {
            $worker = new PreprocessWorker($config);
            $workerCount = $config->workers > 0 ? $config->workers : WorkerPool::detectCpuCount();
            $pool = new WorkerPool(workers: $workerCount);
            $task = static function (array $batch) use ($worker, $output): array {
                return $worker->process($batch, $output);
            };

            $state->debug($output, sprintf('preprocess: processing %d files across %d workers [%s]', count($toProcess), $workerCount, MemoryDebug::getMemoryUsage()));

            $processedFilesSet = [];
            $skippedFilesSet   = []; // files that parsed but yielded zero blocks (0.6)
            $errorFiles        = []; // files that emitted an error row — never cache-saved
            $sinceYield        = 0;

            $state->debug($output, sprintf('preprocess: collecting results from workers... [%s]', MemoryDebug::getMemoryUsage()));
            foreach ($pool->runStreaming($toProcess, $task) as $row) {
                // Counter law (0.6): every fresh file lands in exactly one
                // bucket — a 'block'-emitting file counts as processed, a
                // 'skipped' file as without-blocks, an 'error' file as a
                // parse error. The worker emits one error row per file and
                // one skipped row per zero-block file, so per-row counting
                // here is per-file counting. Previously EVERY row (errors
                // included) entered $processedFilesSet, so a file that only
                // produced an error was double-counted (processed + error)
                // and a zero-block file was counted as processed while
                // contributing no blocks — scanned vs processed vs the
                // blocks/buckets below never balanced.
                if ($row['type'] === 'error') {
                    $state->parseErrors++;
                    $errorFiles[$row['file']] = true;
                } elseif ($row['type'] === 'block') {
                    $processedFilesSet[$row['file']] = true;
                    /** @var Block $b */
                    $b = $row['block'];
                    $blocks[] = $b;
                } elseif ($row['type'] === 'skipped') {
                    $skippedFilesSet[$row['file']] = true;
                }
                $state->processedFiles = count($processedFilesSet);
                $state->skippedFiles = count($skippedFilesSet);

                if (++$sinceYield >= self::YIELD_EVERY) {
                    $sinceYield = 0;
                if ($state->cancelled) {
                    break;
                }
                    $state->sampleMemory();
                    $state->debug($output, sprintf('preprocess: processed %d files, %d blocks so far [%s]', $state->processedFiles, count($blocks), MemoryDebug::getMemoryUsage()));
                    $this->listener->onFilePreprocessed(
                        $state->processedFiles, $state->reusedFiles, $state->parseErrors,
                    );
                    yield Stage::Preprocessing;
                }
            }
            unset($processedFilesSet, $skippedFilesSet);

            if ($store !== null) {
                $byFile = [];
                foreach ($blocks as $b) {
                    $byFile[$b->file][] = $b;
                }
                // Belt for buffer-and-discard (Hazard-1): PreprocessWorker
                // now flushes a file's rows only when the whole file
                // succeeded, so no file can legitimately appear here with
                // both block rows and an error row. If a future producer
                // breaks that all-or-nothing contract, drop the file from
                // cache-save rather than persist a truncated per-file block
                // list — a later cache hit on a partial list would hide the
                // rest of the file forever.
                foreach ($errorFiles as $erroredFile => $_) {
                    unset($byFile[$erroredFile]);
                }
                $state->debug($output, sprintf('preprocess: saving %d files to cache... [%s]', count($byFile), MemoryDebug::getMemoryUsage()));
                foreach ($byFile as $file => $list) {
                    $store->save($file, $list);
                }
                $state->debug($output, sprintf('preprocess: cache save complete [%s]', MemoryDebug::getMemoryUsage()));
            }
            $this->listener->onFilePreprocessed(
                $state->processedFiles, $state->reusedFiles, $state->parseErrors,
            );
        }

        // Assign IDs (after collecting from all sources).
        //
        // CI-85 determinism: ids must not depend on arrival order. The old
        // numbering used the global position in $blocks — an index that
        // mixes cache-restored files (scan order) with worker-stream rows
        // (completion timing), so the same corpus scanned under a different
        // host, worker schedule, or sibling kind opt-in could hand a block
        // `_9` here and `_10` there. That leaked byte-for-byte into report
        // surfaces that embed ids (`template.id`, `evidence_diff.member`,
        // `pairs[].blockA/B`) and into member ordering (clusters sort by
        // strcmp of ids, medoid ties break on iteration order). Two fixes
        // in one place, both content-derived:
        //   1. sort $blocks canonically by (file, start, end, kind, name,
        //      structuralHash) — the extraction position of a block is a
        //      function of its source, so this order is host- and
        //      schedule-independent (a shared absolute-path prefix does
        //      not reorder anything within one root);
        //   2. number each block by its rank WITHIN its 8-hex hash prefix
        //      group instead of the global index, so adding or removing an
        //      unrelated family (e.g. opting an extra kind into the run)
        //      never renumbers the clones a stable cluster reports on.
        // Uniqueness still holds: two blocks cannot share the full key
        // (file, start, end, kind, name) — the extractor emits each
        // traversable node once.
        $state->debug($output, sprintf('preprocess: assigning IDs to %d blocks... [%s]', count($blocks), MemoryDebug::getMemoryUsage()));
        usort($blocks, static fn (Block $a, Block $b): int => [
            $a->file, $a->range->start, $a->range->end, $a->kind, $a->name ?? '', $a->structuralHash,
        ] <=> [
            $b->file, $b->range->start, $b->range->end, $b->kind, $b->name ?? '', $b->structuralHash,
        ]);
        $rankByPrefix = [];
        foreach ($blocks as $b) {
            $prefix = substr($b->structuralHash, 0, 8);
            $b->id = $prefix . '_' . ($rankByPrefix[$prefix] = ($rankByPrefix[$prefix] ?? -1) + 1);
            // Item 5.1: repo/scope labels are run context, so they are
            // (re)computed for every block here — cache-restored and
            // freshly extracted alike — after the cache-save pass above.
            // Nothing label-shaped therefore reaches the `.idx` blobs:
            // a stale cache cannot serve a stale (or silently empty)
            // label because the stamp always overwrites the default.
            RepoLabels::stamp($b, $config->paths);
        }
        $state->debug($output, sprintf('preprocess: ID assignment complete, transitioning to next stage... [%s]', MemoryDebug::getMemoryUsage()));

        $state->blocks = $blocks;
        $state->timings['preprocess'] = microtime(true) - $tPre;

        // Counter labels are deliberate (0.6): 'files' is what the scanner
        // handed this stage; 'reused' came from the per-file index cache;
        // 'processed' produced at least one comparable block; 'no blocks'
        // parsed cleanly but yielded none (enums, interfaces, const-
        // definitions, ...); 'parse errors' failed to parse. Every scanned
        // file is in exactly one bucket: reused + processed + no blocks +
        // parse errors == files.
        $output->writeln(sprintf(
            "<info>phpdup</info> %s (%d reused · %d processed · %d no blocks · %d parse errors) → %d blocks",
            CountPhrase::files(count($files)), $state->reusedFiles, $state->processedFiles, $state->skippedFiles, $state->parseErrors, count($blocks),
        ));

        if ($this->showStats) {
            $this->printStats($output, $blocks);
        }

        $this->checkMemory($output);
    }

    /** @param list<Block> $blocks */
    private function printStats(OutputInterface $out, array $blocks): void
    {
        $kinds = [];
        $sizes = [];
        foreach ($blocks as $b) {
            $kinds[$b->kind] = ($kinds[$b->kind] ?? 0) + 1;
            $sizes[] = $b->size;
        }
        ksort($kinds);
        $out->writeln('  block kinds:');
        foreach ($kinds as $k => $n) {
            $out->writeln(sprintf('    %-10s %d', $k, $n));
        }
        if ($sizes) {
            sort($sizes);
            $p = fn(float $q): int => $sizes[(int)floor($q * (count($sizes) - 1))];
            $out->writeln(sprintf(
                '  size: min=%d p50=%d p90=%d p99=%d max=%d',
                $sizes[0], $p(0.5), $p(0.9), $p(0.99), end($sizes)
            ));
        }
    }
}
