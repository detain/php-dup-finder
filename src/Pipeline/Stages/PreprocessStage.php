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
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

final class PreprocessStage implements CooperativeStageInterface
{
    /** Yield to the runtime every N records streamed from workers. */
    private const YIELD_EVERY = 32;

    /**
     * How many individual parse failures --verbose lists before it folds
     * (once-S37 / php-dup-finder-once-080): enough to triage a broken
     * tree by eye; past it the tail is noise and the count line carries
     * the truth.
     */
    private const PARSE_FAILURE_DISPLAY = 50;

    private readonly ProgressListener $listener;

    /**
     * @param list<string> $pluginExclusionNotes once-S66: notes for plugins
     *        the Command-side screen excluded; appended to
     *        PipelineState::$scanNotes when this stage runs.
     */
    public function __construct(
        private readonly bool $useCache,
        private readonly bool $showStats = false,
        ?ProgressListener $listener = null,
        private readonly int $maxMemoryMb = 0,
        private readonly array $pluginExclusionNotes = [],
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

        // once-S66 (finding once-075): the Command-side plugin screen
        // excluded broken entries and handed their notes here. ScanningStage
        // snapshotted $state->scanNotes BEFORE this stage runs, so these are
        // appends onto the once-S37 channel — ReportStage threads the full
        // list into the report payload (summary.scan_notes rides the
        // owner-gated 1.8 emit window; the carriage itself is live).
        foreach ($this->pluginExclusionNotes as $note) {
            $state->scanNotes[] = $note;
        }

        $configKey = $this->indexStoreConfigKey($config);
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
                    // shell-042 (aborted-run accounting): cancel is polled
                    // only at this yield boundary, so breaking here abandons
                    // the rest of the loop - reused+toProcess can fall short
                    // of scanned on an aborted run. Partial report by design.
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
        //
        // shell-008: the child task is a GENERATOR (PreprocessWorker::
        // processStream), mirroring the pair-score task in
        // ClusterStage::scorePairs. With the old array-returning task,
        // runStreaming's `foreach ($task($chunk) ...)` in the child only
        // began writing after the child had materialized the WHOLE chunk —
        // every Block + canonical AST alive at once per child (peak RSS)
        // and zero records observable until a full chunk finished (the
        // YIELD_EVERY repaint contract degenerated to chunk granularity).
        // Per-file yields make both live; row CONTENT is unchanged, so
        // Golden reports stay byte-identical (ids are canonical-sorted at
        // assignCanonicalIds below, independent of arrival order).
        $tPre = microtime(true);
        if ($toProcess) {
            // engine-019: --no-cache must reach the worker's on-disk AST
            // cache too, not only the IndexStore above. Threading useCache
            // into the worker makes a no-cache run parse fresh and leave
            // the cache dir byte-untouched (no blob writes, no serving of
            // planted blobs); cache-enabled runs keep the historic warm
            // parser cache exactly as before.
            $worker = new PreprocessWorker($config, astDiskCache: $this->useCache);
            $workerCount = $config->workers > 0 ? $config->workers : WorkerPool::detectCpuCount();
            $pool = new WorkerPool(workers: $workerCount);
            $task = static function (array $batch) use ($worker, $output): \Generator {
                yield from $worker->processStream($batch, $output);
            };

            $state->debug($output, sprintf('preprocess: processing %d files across %d workers [%s]', count($toProcess), $workerCount, MemoryDebug::getMemoryUsage()));

            $processedFilesSet = [];
            $skippedFilesSet   = []; // files that parsed but yielded zero blocks (0.6)
            $errorFiles        = []; // files that emitted an error row — never cache-saved
            $sinceYield        = 0;

            $state->debug($output, sprintf('preprocess: collecting results from workers... [%s]', MemoryDebug::getMemoryUsage()));
            // shell-S12 (shell-041): hand the pool the state->cancelled
            // probe. The yield-boundary poll below can only fire while
            // records flow; this lets the first Ctrl-C reach the pool
            // itself when a child stalls and none do.
            $cancel = static function () use ($state): bool {
                return $state->cancelled;
            };

            foreach ($pool->runStreaming($toProcess, $task, $cancel) as $row) {
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
                    // once-S37 (php-dup-finder-once-080): the worker row
                    // ALWAYS carries the real parser message (PreprocessWorker
                    // : 'parse error' fallback included) — the old arm read
                    // the count and threw the why away. Keep both: the file
                    // and its message ride $state->parseFailures for the
                    // --verbose list and the future JSON summary fields.
                    $message = $row['message'] ?? null;
                    $state->parseFailures[] = [
                        'file' => (string) $row['file'],
                        'message' => is_string($message) && $message !== '' ? $message : 'parse error',
                    ];
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
                    // shell-042 (aborted-run accounting): breaking at this
                    // yield boundary abandons the rest of the streamed rows,
                    // so processed+skipped+parseErrors can fall short of the
                    // files handed to the pool. Partial report by design.
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
        // assignCanonicalIds() establishes a content-derived canonical order
        // before any id is minted, and numbers blocks by rank WITHIN their
        // hash-prefix family, so ids never depend on arrival order and
        // unrelated families coming or going never renumber a stable
        // cluster's clones. The method docblock states the exact guarantee
        // boundary: total at the mint/members[] layer up to emit-symmetry;
        // normalization-folded raw-AST projections (holes[].observed) are
        // outside it — r2 review corrected the r1 false key-uniqueness
        // comment, r3 corrects the r2 over-strong invisibility wording.
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

        // once-S37 (php-dup-finder-once-080): the count above is honest but
        // anonymous. At --verbose the run names every failing file with the
        // parser's real message — the difference between '26 parse errors'
        // and being able to fix them. Both fields are raw bytes (a crafted
        // filename, a parser message quoting source) so markup-escape at the
        // sink, the shell-S34 law ScanningStage::escapeMarkup documents.
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE && $state->parseFailures !== []) {
            $shown = 0;
            foreach ($state->parseFailures as $failure) {
                if ($shown >= self::PARSE_FAILURE_DISPLAY) {
                    $output->writeln(sprintf(
                        '<comment>phpdup: … and %d more parse failure(s).</comment>',
                        count($state->parseFailures) - $shown,
                    ));
                    break;
                }
                $output->writeln(sprintf(
                    '<comment>phpdup: parse failure %s: %s</comment>',
                    self::escapeMarkup($failure['file']),
                    self::escapeMarkup($failure['message']),
                ));
                $shown++;
            }
        }

        if ($this->showStats) {
            $this->printStats($output, $blocks);
        }

        $this->checkMemory($output);
    }

    /**
     * Build the per-file IndexStore config-key from the fields that
     * change what a cached Block snapshot would carry (shell-S16 /
     * finding shell-027: extracted from iter() so the binding is a
     * nameable, testable contract — ClusterStage::cacheConfigKey
     * precedent, mirrored by tests/Unit/Persistence/IndexStoreTest.php
     * through reflection).
     */
    private function indexStoreConfigKey(\Phpdup\Cli\Config $config): string
    {
        return sha1(serialize([
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
            // shell-027: --low-memory rewrites every stored n-gram bag
            // into CompactNgramBag's int-keyed 63-bit form (and folds the
            // canonical AST through the shared node pool). A low-memory
            // cache replayed into a default run would feed compact bags
            // next to freshly minted 64-bit-hex ones with no error — the
            // exact misfeed the fingerprintIdMode binding above exists to
            // refuse, one knob over. Key-namespace route only: no
            // PARSER_VERSION bump (fingerprintIdMode precedent). The
            // consequence is a one-time invalidation of every existing
            // .idx tree — a pre-027 snapshot carries a key computed
            // WITHOUT this field, so its config_key line simply mismatches
            // and the store misses cleanly into a fresh save (shell-S18's
            // header gate does the work; nothing crashes, nothing serves
            // stale bags). Disclosed in the lane report.
            $config->lowMemory,
            // once-026 (HIGH) / ENGINE-CONV-04: the plugin class list is
            // consumed at bagging time — PluginRegistry::fromClassNames
            // feeds every visit() through the normalizer the worker runs
            // before hashing (PreprocessWorker.php:170-171), and that
            // worker's own tooling key already binds the list (:188). A
            // snapshot normalized WITH a plugin replayed into a plugin-free
            // run (or vice-versa) serves the wrong canonical ASTs with no
            // error — the AGENTS.md normalization-plugin doctrine names
            // BOTH cache keys as the threading sites; this completes the
            // IndexStore leg. Key-namespace change only: no PARSER_VERSION
            // bump (fingerprintIdMode precedent).
            $config->normalizationPlugins,
            // once-026: the per-directory map rewrites what effectiveFor()
            // derives for whole subtrees (min_block_size, normalization_mode,
            // ngram_size...), so two runs with identical top-level fields
            // but different override maps mint different block species from
            // byte-identical files — and file_hash cannot see that, because
            // the file bytes ARE unchanged. The map is exactly the run
            // context the config key exists to carry. Bound raw
            // (dir => shapeOverrides): combined with the top-level fields
            // already in this list it fully determines effectiveFor(), so
            // any behavior-affecting change moves the key. Same one-time
            // invalidation posture as every binding above (shell-S18
            // header gate; clean miss, never a stale serve).
            $config->perDirectoryOverrides,
        ]));
    }

    /**
     * Canonical block ordering + content-derived ids (CI-85 T2, r3 wording).
     *
     * Sort key: (file, range start, range end, kind, namespace, class,
     * name, structuralHash) — the per-member fields of the report's
     * members[] rows. (size is also emitted but content-derived, hence
     * equal under a hash tie; repo/scope are run-context off the file
     * path, equal by the file key, stamped after mint.) Ids are then
     * rank-within-hash8-prefix, which isolates families from each
     * other's presence entirely.
     *
     * DETERMINISM BOUNDARY, stated exactly (the r2 review caught my r2
     * wording overclaiming twice — "kills every OBSERVABLE tie" / "no
     * report byte can observe the permutation" / "immaterial to bytes"
     * were FALSE as written; the erratum is owned in this method's
     * successor commit message):
     *   GUARANTEED — (i) the r1 class-asymmetric tie leak is closed:
     *       members[] rows embed `class`, six keys allowed such ties to
     *       reach report bytes (reviewer reproduced the 2/4 report-md5
     *       split), and namespace+class remove exactly that exposure;
     *   (ii) for an orbit that still ties under eight keys, the minted
     *       family labels and every members[]-field projection are
     *       host-independent, because tied members' emitted fields are
     *       byte-equal and ids are positional labels within the family.
     *   NOT GUARANTEED — normalization-equality is the boundary, and the
     *       raw AST remains a downstream handle: default/aggressive
     *       modes fold LITERAL differences, so orbit members can have
     *       differing raw ASTs (reviewer TiedLit craft: same-line
     *       closures differing only `+1` vs `+2` share hash 33aea77f),
     *       and `holes[].observed` is projected from the raw AST in
     *       member-position order (AntiUnifier seeds and walks members;
     *       JsonReporter dumps the list unsorted). Such an orbit can
     *       permute holes[].observed ORDER bytes in the JSON/HTML/
     *       refactor-tests surfaces under --no-lazy-ast + adversarial
     *       arrival. Production default (lazy-AST) fail-fast-crashes on
     *       this exact shape in BlockAstLoader (pre-existing r1 Minor-3)
     *       — that SHIELDS the surface only as an architectural
     *       accident, not a designed guard. Residual exposure is filed
     *       as a wave-5 OWNER-DECISION backlog row (normalize observed
     *       at mint / pin arrival order / declare --no-lazy-ast
     *       best-effort). Inside-orbit physical-to-label binding also
     *       still rests on per-file emission contiguity + PHP>=8.0
     *       stable usort — true today; the pinning test
     *       (PreprocessStageIdDeterminismTest) covers the mint-label and
     *       members[]-field layer ONLY, not raw-AST projections.
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

    /**
     * $bytes as literal console text (once-S37; the shell-S34 law,
     * mirrored from ScanningStage where its rationale is documented in
     * full): raw path/ref bytes inside a writeln() line are markup — and
     * injection — material. OutputFormatter::escape() passes ordinary
     * values through byte-for-byte, so no render pin can drift.
     */
    private static function escapeMarkup(string $bytes): string
    {
        return OutputFormatter::escape($bytes);
    }
}
