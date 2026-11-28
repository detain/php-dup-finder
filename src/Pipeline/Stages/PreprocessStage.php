<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Index\SequenceIndex;
use Phpdup\Parallel\PreprocessWorker;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Persistence\IndexStore;
use Phpdup\Pipeline\CooperativeStageInterface;
use Phpdup\Scanning\RepoLabels;
use Phpdup\Pipeline\NullProgressListener;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\ProgressListener;
use Phpdup\Pipeline\Stage;
use Phpdup\Text\LangProfiles;
use Phpdup\Text\TextBlockFactory;
use Phpdup\Text\TextFileTokens;
use Phpdup\Text\TextRunHarvester;
use Phpdup\Text\TextTokenizer;
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

    /**
     * Item 2.1 doc parameter fragment_min_sites: a mined run is reported
     * only when at least this many sites carry it.
     */
    private const FRAGMENT_MIN_SITES = 3;

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

        // Item 7.1: the token tier partition. Under an armed run ('text'
        // named explicitly in the kind roster — Config::tokenTierArmed())
        // the non-PHP files LangProfiles speaks are pulled out of the PHP
        // pipeline HERE, before the cache check and the workers, because
        // neither may ever see non-PHP bytes (a PHP parse of a .js would
        // mint a bogus parse error). They come back as kind-'text' blocks
        // through the harvest below. Unarmed, $textFiles stays empty and
        // this whole lane is inert — the byte-identical default posture
        // the frozen goldens require.
        $scannedFiles = $files; // the summary line counts the FULL scan
        $textFiles    = [];
        $tokenTier    = $config->tokenTierArmed();
        if ($tokenTier) {
            $phpFiles = [];
            foreach ($files as $f) {
                if (LangProfiles::profileFor($f) !== null) {
                    $textFiles[] = $f;
                } else {
                    $phpFiles[] = $f;
                }
            }
            $files = $phpFiles;
        }

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
        // Item 2.1: the §5c miner's ONE row stream has its consumer armed
        // by demand — the 'fragment' kind selection. (3.x census legs add
        // their own demand predicates here; they must NOT get a second
        // walker or a second row type.)
        $sequenceDemand = in_array(BlockExtractor::FRAGMENT_KIND, $config->allowedKinds, true);
        // Item 3.1 ingestion (MINE-D): the census demand rides the SAME
        // walker and the SAME row stream — a second predicate on the
        // worker constructor, never a second pass (§5c one-walker law).
        // The Config invariant has already guaranteed the fragment roster
        // an armed mining needs; the predicate is read off the knob.
        $idiomDemand = $config->idiomMining;
        // Item 3.2 adoption (MINE-D-2): the summary re-queries the census
        // 3.1 just mined — the Config invariant guarantees idiom_mining is
        // on whenever this is, so the store-bypass posture above already
        // covers it. Adoption mints NO mined rows and persists NOTHING:
        // its derivation runs master-side once blocks exist (built at the
        // tail below), which is why it deliberately does NOT join
        // indexStoreConfigKey (pinned: the key is invariant across this
        // flip — warm replays re-derive identical adoption from
        // re-mined-complete corpora; an armed run never takes the store).
        $helperDemand = $config->helperAdoption;
        // Item 3.5 call sequences (MINE-E): rides the SAME walker and the
        // SAME row stream (the 'calls' flavor is a third predicate on the
        // worker constructor, never a second pass). The Config invariant
        // has already paired it with idiom_mining, so the store-bypass
        // posture below (armed idiom => no store) covers it transitively
        // — and the flavor mints rows, so it DOES join
        // indexStoreConfigKey (unlike adoption): were the bypass ever
        // relaxed, an armed-calls snapshot must never be served to an
        // unarmed run. Second belt, same doctrine.
        $callDemand = $config->callSequence;
        // Item 3.3 literal census (MINE-E): the one flavor that reads the
        // file's WHOLE raw parsed tree (class members and top-level
        // statements the block lists skip) — still from the same
        // in-memory artifact the extractor consumed: no second parse, no
        // second file read, §5c honoured. It carries NO fragment or idiom
        // precondition (literals are visible without the roster), so it
        // needs its OWN store-bypass term below; like 'calls' it mints
        // rows and joins indexStoreConfigKey.
        $literalDemand = $config->literalCensus;
        // Warm asymmetry, obeyed as the substrate contract states it:
        // cache-reused files emit NO mined rows of ANY flavor, so an armed
        // run that replayed blocks from IndexStore would ingest a census
        // missing every reused file while presenting it as complete — the
        // exact silent-misfeed the 2.1 suppression pass designed its
        // block-relative fields to avoid, and the census has no such
        // persisted carrier (the miner's truth lives in no Block field;
        // AstSerializer/BlockExtractor are other lanes' zones to change).
        // The armed run therefore takes NO store: every file passes the
        // worker (the disk AST blob-cache under astDiskCache still saves
        // the parse cost — rows are emitted from cached blobs too), the
        // census is corpus-complete, and nothing is written or read under
        // any key this flip could stale-serve. The idiomMining leg in
        // indexStoreConfigKey is the second belt: were the bypass ever
        // relaxed, an armed snapshot could never be served unarmed.
        $store = ($this->useCache && $config->incremental && !$idiomDemand && !$literalDemand)
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
            $worker = new PreprocessWorker($config, astDiskCache: $this->useCache, idiomMining: $idiomDemand, callSequence: $callDemand, literalCensus: $literalDemand);
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
                } elseif ($row['type'] === 'oversized') {
                    // Item 0.6 (oversized leg): one row per file that had
                    // at least one fully-dropped oversized block (the
                    // worker stages it inside the same buffer-and-discard
                    // envelope as the block rows, so a poison file never
                    // leaks a count). Scalar payload only — the count is
                    // run-total; files stay identifiable through the row,
                    // the CLI/JSON surface the honest aggregate.
                    $state->oversizedBlocks += (int) ($row['dropped'] ?? 0);
                } elseif ($row['type'] === 'mined') {
                    // Item 2.1 (§5c): the one generic scalar row — flavor-
                    // keyed payload, v1 flavor stmtseq. Feeds the single
                    // SequenceIndex when a consumer armed demand; silently
                    // ignored otherwise (rows cannot appear without demand
                    // today — the walker is demand-gated — but the row
                    // stream is deliberately dumb so 3.x flavors never
                    // touch this dispatch again).
                    //
                    // Item 3.1 ingestion (MINE-D): the 'idiom' flavor is
                    // the first 3.x rider of that promise — it lands HERE,
                    // in the same 'mined' dispatch, keyed by flavor. The
                    // stmtseq arm below is unchanged; the idiom rows were
                    // already inert against it (payload entries carry
                    // sites/edges, never 'hashes'), and the explicit
                    // flavor key keeps each arm honest even with both
                    // demands armed on one run.
                    if (($row['flavor'] ?? '') === 'idiom') {
                        if ($idiomDemand) {
                            $index = $state->sequenceIndex ??= new SequenceIndex();
                            // armIdioms is idempotent; calling it per row
                            // keeps the ingest one line (the SequenceIndex
                            // refuses idiom payloads while unarmed — Fail
                            // Fast — so the arm must precede the record).
                            $index->armIdioms();
                            // Item 3.2 (MINE-D-2): adoption rides the same
                            // armed census — per-row idempotent arm, no new
                            // row flavor, nothing extra over the wire.
                            if ($helperDemand) {
                                $index->armHelpers();
                            }
                            $index->recordIdiomFile(
                                (string) ($row['file'] ?? ''),
                                (string) ($row['payload'] ?? ''),
                            );
                        }
                    } elseif (($row['flavor'] ?? '') === 'calls') {
                        // Item 3.5 ingestion (MINE-E): the 'calls' flavor
                        // is the third rider of the 2.1 promise, keyed by
                        // flavor exactly like the idiom arm above. Its
                        // payload entries carry names/lines/args and never
                        // 'hashes', so an explicit branch is MANDATORY:
                        // without one the tolerate-unknown continue in the
                        // stmtseq arm below would silently swallow every
                        // calls row.
                        if ($callDemand) {
                            $index = $state->sequenceIndex ??= new SequenceIndex();
                            // armCalls is idempotent; refusing-while-
                            // unarmed lives in recordCallFile (Fail Fast).
                            $index->armCalls();
                            $index->recordCallFile(
                                (string) ($row['file'] ?? ''),
                                (string) ($row['payload'] ?? ''),
                            );
                        }
                    } elseif (($row['flavor'] ?? '') === 'literal') {
                        // Item 3.3 ingestion (MINE-E): same doctrine, own
                        // flavor key; the 6-arity literal rows carry no
                        // 'hashes' key either, so they too need the
                        // explicit branch ahead of the stmtseq arm.
                        if ($literalDemand) {
                            $index = $state->sequenceIndex ??= new SequenceIndex();
                            $index->armLiterals();
                            $index->recordLiteralFile(
                                (string) ($row['file'] ?? ''),
                                (string) ($row['payload'] ?? ''),
                            );
                        }
                    } elseif ($sequenceDemand) {
                        $entries = json_decode((string) ($row['payload'] ?? ''), true);
                        if (is_array($entries)) {
                            $index = $state->sequenceIndex ??= new SequenceIndex();
                            $file = (string) $row['file'];
                            foreach ($entries as $e) {
                                if (!is_array($e) || !isset($e['hashes']) || !is_array($e['hashes'])) {
                                    continue; // tolerate future payload shape
                                }
                                $index->recordList(
                                    $file,
                                    array_values(array_map('strval', $e['hashes'])),
                                    (int) ($e['firstLine'] ?? 0),
                                    (int) ($e['lastLine'] ?? 0),
                                    (string) ($e['ownerKind'] ?? ''),
                                );
                            }
                        }
                    }
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

        // Item 7.1: the token tier harvest — cross-file maximal window
        // matches over blanked non-PHP token streams, appended to $blocks
        // AFTER the cache-save pass (tier runs are derived across files, so
        // they are run-local: nothing about them persists in the per-file
        // IndexStore blobs) and BEFORE id assignment (they are ordinary
        // canonical-order citizens from here on: suppress pass ignores the
        // kind, assignCanonicalIds/RepoLabels/clusterer/reporters see plain
        // Blocks). Counter law joins the 0.6 census: every tier file lands
        // in exactly one bucket — a file whose runs made it past the floors
        // counts as processed, one that yielded none as without-blocks, an
        // unreadable one as a parse error — so the balance law
        // scanned == reused + processed + no-blocks + errors keeps holding
        // over the FULL scan including text files.
        if ($tokenTier && $textFiles !== []) {
            $factoryRuns = [];
            $sinceYield  = 0;
            foreach ($textFiles as $f) {
                $src = @file_get_contents($f);
                if ($src === false) {
                    $state->parseErrors++;
                    $state->parseFailures[] = [
                        'file'    => (string) $f,
                        'message' => 'token tier: file is unreadable',
                    ];
                    continue;
                }
                $profile      = LangProfiles::profileFor($f);
                if ($profile === null) {
                    continue; // unreachable (partition admitted by profile); belt
                }
                $factoryRuns[] = new TextFileTokens($f, $profile, TextTokenizer::tokenize($src, $profile));
                if (++$sinceYield >= self::YIELD_EVERY) {
                    $sinceYield = 0;
                    if ($state->cancelled) {
                        break;
                    }
                    yield Stage::Preprocessing;
                }
            }

            $harvester = new TextRunHarvester($config->ngramSize);
            $runs      = $harvester->harvest($factoryRuns);
            foreach ($harvester->notes() as $note) {
                $state->scanNotes[] = $note; // honest disclosure at the note channel
            }

            $tierFingerprinter = new NgramFingerprint(
                $config->ngramSize,
                $config->lowMemory,
                $config->fingerprintIdsUnnumbered(),
            );
            $tierFactory = new TextBlockFactory($tierFingerprinter, new SubtreeHasher());

            /** @var array<string, list<\Phpdup\Text\TextRun>> $runsByFile */
            $runsByFile = [];
            foreach ($runs as $r) {
                $runsByFile[$r->file()][] = $r;
            }
            foreach ($factoryRuns as $ft) {
                if (isset($runsByFile[$ft->file])) {
                    $state->processedFiles++;
                    foreach ($runsByFile[$ft->file] as $r) {
                        $blocks[] = $tierFactory->fromRun($r);
                    }
                } else {
                    $state->skippedFiles++;
                }
            }
            $this->listener->onFilePreprocessed(
                $state->processedFiles, $state->reusedFiles, $state->parseErrors,
            );
        }

        // Item 2.1 (§5c): corpus-side suppression of the mined runs —
        // AFTER cache-save (suppressed windows persist as maximality
        // evidence, which is what makes a warm replay see the SAME
        // dictionary as a cold run: the pass below reads Block fields
        // only, never SequenceIndex, so cache-reused files contribute
        // sites identically) and BEFORE id assignment (ranks mint over
        // the survivors clustering actually sees).
        $blocks = self::suppressNonMaximalFragments($blocks);

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
        //
        // Item 5.2 (W2b): the vendored marker rides the same pass with the
        // same law — run context, master-side only (no mark crosses the
        // worker wire), overwritten from THIS run's roots before any
        // consumer reads one. Detection is always-on (inert to output by
        // default); the demotion it feeds is armed by --scope at the
        // report seam. Fail-open lock notes join the run's skip-notes
        // channel (FileScanner lineage), never a silent swallow.
        $vendorMarker = \Phpdup\Scanning\VendorMarker::forRoots($config->paths);
        foreach ($blocks as $b) {
            RepoLabels::stamp($b, $config->paths);
            $vendorMarker->stamp($b);
        }
        foreach ($vendorMarker->notes() as $note) {
            $state->scanNotes[] = $note;
        }
        $state->debug($output, sprintf('preprocess: ID assignment complete, transitioning to next stage... [%s]', MemoryDebug::getMemoryUsage()));

        $state->blocks = $blocks;
        // Item 3.2 adoption (MINE-D-2): master-side derivation over the
        // shared substrate once the block set is final (census re-query +
        // statement-list spans; §5c one-miner law satisfied by asking,
        // never re-walking). Gated on the actual arm, not the raw knob:
        // a corpus that mined no rows armed nothing, and buildHelpers()
        // refuses over an unarmed index (Fail Fast doctrine shared with
        // recordIdiomFile()). ReportStage/display routing of the result
        // is a reported follow-on (ROLLUP's reporting train owns the
        // surfaces); the ledger is reachable meanwhile via
        // $state->sequenceIndex->helperAdoption().
        $adoptIndex = $state->sequenceIndex;
        if ($helperDemand && $adoptIndex !== null && $adoptIndex->helpersArmed()) {
            $adoptIndex->buildHelpers($state->blocks);
        }
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
            CountPhrase::files(count($scannedFiles)), $state->reusedFiles, $state->processedFiles, $state->skippedFiles, $state->parseErrors, count($blocks),
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
            // Item 1.2 (three-key law, index leg): window_oversized decides
            // whether an oversized body contributes 'window' blocks to the
            // store at all — two postures mint different block species
            // from byte-identical files, exactly the misfeed the
            // allowedKinds binding above refuses for the 1.3 folds. The
            // worker's tooling key carries the same value (PreprocessWorker
            // $toolFor). Key-namespace change only: no PARSER_VERSION bump
            // (fingerprintIdMode precedent); consequence is a one-time
            // invalidation of every existing .idx tree, disclosed in the
            // lane report.
            $config->windowOversized,
            // Item 5.2 .gitignore leg (W2a): honor_gitignore decides which
            // files reach the store at all. The flag never alters a stored
            // file's block CONTENT, so this binding is prophylactic — the
            // allowedKinds/windowOversized doctrine applied to the whole
            // file-membership question: a snapshot set minted under one
            // posture must not masquerade as the other posture's output.
            // Key-namespace change only: no PARSER_VERSION bump
            // (fingerprintIdMode precedent); consequence is a one-time
            // invalidation of every existing .idx tree (shell-S18 header
            // gate: clean miss into a fresh save), disclosed in the lane
            // report.
            $config->honorGitignore,
            // Item 3.1 ingestion (MINE-D): idiom_mining never alters stored
            // block CONTENT (the mined rows live beside blocks, not inside
            // them), but an armed run's WHOLE census depends on every file
            // passing the worker — cache-reused files emit no mined rows
            // (the warm asymmetry law). iter()'s store bypass for armed
            // runs is the correctness belt for that; this binding is the
            // prophylactic second one in the honorGitignore doctrine:
            // armed and unarmed snapshots must never masquerade as each
            // other's output, so flipping the demand invalidates warm
            // caches once. Key-namespace change only, no PARSER_VERSION
            // bump (fingerprintIdMode precedent).
            $config->idiomMining,
            // Items 3.5/3.3 (MINE-E): same doctrine as the idiom binding
            // above, one row per new flavor. Both mint mined rows beside
            // the stored blocks (never altering block CONTENT), both are
            // store-bypassed in iter() when armed (call transitively via
            // its idiom_mining Config invariant; literal by its own gate
            // term), and both flips invalidate warm .idx trees exactly
            // once. Key-namespace change only: no PARSER_VERSION bump
            // (fingerprintIdMode precedent).
            $config->callSequence,
            $config->literalCensus,
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

    /**
     * Item 2.1 §5c/2.1 suppression — the doc's three rules, expressed on
     * run-local evidence every fragment carries:
     *   S-eq: the run equals a whole block ALREADY MINTED (a declaration
     *       body present in the roster, or a top-level bucket the script
     *       fold minted) — the doc's "suppress a fragment when it equals
     *       a whole block already clustered", stated on extraction
     *       evidence because clustering has not happened yet at this
     *       seam.
     *   S-floor: fewer than FRAGMENT_MIN_SITES (doc fragment_min_sites=3)
     *       sites carry the run's CONTENT — support is counted on the
     *       per-block (run-local normalized) structuralHash, which is
     *       offset-invariant: the identical 4-statement run in two
     *       functions with different surrounding bodies counts as the
     *       same run (list-relative keys are not: first-appearance
     *       variable numbering shifts with the list prefix).
     *   S-max:   a one-statement EXTENSION of the run exists in the same
     *       file — an enumerated (pos±1, size+1) window — whose support
     *       is >= this run's. The chain argument makes ONE-step lookahead
     *       exact AMONG MINTED WINDOWS: any site of an s+2 window is a
     *       site of its s+1 prefix, so non-maximality always shows a
     *       one-step witness whenever that s+1 intermediate was itself
     *       minted — a window below the node-size mint floor leaves no
     *       witness slot and a non-maximal run can survive (narrow,
     *       disclosed v1 hole; it bites only tiny-statement runs).
     *       Cross-file asymmetric containment (the longer run
     *       appears only inside ANOTHER file's body) is not suppressed
     *       in v1 — that is the §5c nested-family law shared with
     *       3.1/6.3/7.2, whose SequenceIndex substrate this pass feeds.
     * Nested chains with strictly falling support survive at every step
     * (5,4,3…) — honest "maximal at this support" findings. v1 stitches
     * GAP-FREE runs only; the doc's type-3 gap budget rides its
     * consumers.
     *
     * The pass MUST run over the union of reused and freshly processed
     * blocks and MUST NOT consult SequenceIndex (warm files emit no
     * rows) — that asymmetry is what keeps cold and warm runs identical.
     *
     * @param list<Block> $blocks
     * @return list<Block>
     */
    private static function suppressNonMaximalFragments(array $blocks): array
    {
        $support = [];
        $fragsByFile = [];
        $anyFrag = false;
        foreach ($blocks as $b) {
            if ($b->kind === BlockExtractor::FRAGMENT_KIND) {
                $anyFrag = true;
                if ($b->structuralHash !== '') {
                    $support[$b->structuralHash] = ($support[$b->structuralHash] ?? 0) + 1;
                    $fragsByFile[$b->file][] = $b;
                }
            }
        }
        if (!$anyFrag) {
            return $blocks; // default corpora: untouched, in identity order
        }

        // (pos,size) -> support lookup per file, for the extension witnesses.
        $ext = [];
        foreach ($fragsByFile as $file => $list) {
            foreach ($list as $b) {
                $ext[$file][$b->fragmentPos . ':' . $b->fragmentSize] = $b->structuralHash;
            }
        }

        $kept = [];
        foreach ($blocks as $b) {
            if ($b->kind !== BlockExtractor::FRAGMENT_KIND) {
                $kept[] = $b;
                continue;
            }
            if ($b->fragmentWhole || $b->structuralHash === '') {
                continue; // S-eq (or malformed)
            }
            $own = $support[$b->structuralHash] ?? 0;
            if ($own < self::FRAGMENT_MIN_SITES) {
                continue; // S-floor
            }
            $need = max(self::FRAGMENT_MIN_SITES, $own);
            $pos  = $b->fragmentPos;
            $size = $b->fragmentSize;
            $leftExt  = $ext[$b->file][$pos - 1 . ':' . ($size + 1)] ?? null;
            $rightExt = $ext[$b->file][$pos . ':' . ($size + 1)] ?? null;
            if (($leftExt !== null && ($support[$leftExt] ?? 0) >= $need)
                || ($rightExt !== null && ($support[$rightExt] ?? 0) >= $need)) {
                continue; // S-max: same-file one-step extension carries at least this much support
            }
            $kept[] = $b;
        }

        return $kept;
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
