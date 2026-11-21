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

        // Assign IDs (after collecting from all sources). CI-85 determinism:
        // assignCanonicalIds() establishes a content-derived total order
        // before any id is minted, and numbers blocks by rank WITHIN their
        // hash-prefix family, so ids never depend on arrival order and
        // unrelated families coming or going never renumber a stable
        // cluster's clones. The method docblock carries the exact totality
        // claim and its emit-symmetry caveat — r2 review corrected the
        // false key-uniqueness statement the r1 comment baked in here.
        $state->debug($output, sprintf('preprocess: assigning IDs to %d blocks... [%s]', count($blocks), MemoryDebug::getMemoryUsage()));
        $blocks = self::assignCanonicalIds($blocks);

        // Item 5.1: repo/scope labels are run context, so they are
        // (re)computed for every block here — cache-restored and freshly
        // extracted alike — after the cache-save pass above. Nothing
        // label-shaped therefore reaches the `.idx` blobs: a stale cache
        // cannot serve a stale (or silently empty) label because the stamp
        // always overwrites the default.
        foreach ($blocks as $b) {
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

    /**
     * Canonical block ordering + content-derived ids (CI-85 T2, r2).
     *
     * Sort key: (file, range start, range end, kind, namespace, class,
     * name, structuralHash) — the eight fields a report emits for a
     * block, minus run-context (repo/scope are stamped later; id is what
     * we are minting). Ids are then rank-within-hash8-prefix, which
     * isolates families from each other's presence entirely.
     *
     * TOTALITY, stated honestly (r1 review killed the old claim): the key
     * is NOT strictly total — two distinct nodes can share all eight
     * fields (e.g. two byte-identical closures on one physical line of
     * one method: reviewer craft "Tied"; a real, constructible shape).
     * What makes determinism survive such an orbit is EMIT-SYMMETRY:
     * every downstream handle of a block is either its emitted fields or
     * its id string, two tied blocks have byte-equal emitted fields (that
     * is what the tie means), and the orbit's id labels ({prefix}_k and
     * {prefix}_k+1, …) depend only on the orbit's POSITION in the sorted
     * array — not on which physical block took which slot. So no report
     * byte can observe the permutation. What the r1 six-key tuple DID leak
     * was the class-asymmetric tie (same line, same method name in two
     * classes): members[] rows embed `class`, so there the permutation WAS
     * observable (reviewer seam flipped report md5s). namespace+class in
     * the key close exactly that gap; the residual orbit is provably
     * invisible. Physical-to-label binding inside an orbit rests on
     * per-file emission contiguity (both collection paths flush a file's
     * blocks together) + PHP>=8.0 stable usort — true today, immaterial
     * to bytes; the permanent pin PreprocessStageIdDeterminismTest
     * exercises both shapes under adversarial shuffles.
     *
     * @param list<Block> $blocks
     * @return list<Block> the same blocks, canonically ordered, ids minted
     */
    private static function assignCanonicalIds(array $blocks): array
    {
        usort($blocks, static fn (Block $a, Block $b): int => [
            $a->file, $a->range->start, $a->range->end, $a->kind,
            $a->namespace ?? '', $a->class ?? '', $a->name ?? '', $a->structuralHash,
        ] <=> [
            $b->file, $b->range->start, $b->range->end, $b->kind,
            $b->namespace ?? '', $b->class ?? '', $b->name ?? '', $b->structuralHash,
        ]);
        $rankByPrefix = [];
        foreach ($blocks as $b) {
            $prefix = substr($b->structuralHash, 0, 8);
            $b->id = $prefix . '_' . ($rankByPrefix[$prefix] = ($rankByPrefix[$prefix] ?? -1) + 1);
        }
        return $blocks;
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
