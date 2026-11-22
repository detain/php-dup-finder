<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Clustering\Clusterer;
use Phpdup\Index\BlockIndex;
use Phpdup\Parallel\PairScoreWorker;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Persistence\ClusterCache;
use Phpdup\Pipeline\CooperativeStageInterface;
use Phpdup\Pipeline\NullProgressListener;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\ProgressListener;
use Phpdup\Pipeline\Stage;
use Phpdup\Similarity\EditCostModel;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;
use Phpdup\Util\CountPhrase;
use Phpdup\Util\MemoryDebug;
use Symfony\Component\Console\Output\OutputInterface;

final class ClusterStage implements CooperativeStageInterface
{
    /** Build the cluster-cache config-key from the fields that drive clustering output. */
    private function cacheConfigKey(\Phpdup\Cli\Config $config): string
    {
        return sha1(serialize([
            $config->similarityThreshold,
            $config->treeThreshold,
            $config->maxDocumentFrequency,
            $config->optionalBlocksEnabled,
            $config->optionalBlocksContainment,
            $config->optionalBlocksMinOverlap,
            $config->optionalBlocksMaxPerCluster,
            $config->optionalBlocksMinSegmentLength,
            $this->exactOnly,
            // lazy_ast affects whether Block::$ast is null when clusters are cached.
            // A run with lazy_ast=false will have populated ASTs; reusing a
            // lazy_ast=true cache entry (or vice-versa) causes RefactorStage to
            // either load stale nulls or miss required ASTs depending on mode.
            $config->lazyAst,
            // ngram_size changes each block's fingerprint and thus candidate pairs
            // and Jaccard scores — the cached clusters must be invalidated.
            $config->ngramSize,
            // Item 1.1 (§5a): same argument as ngram_size, one notch more
            // load-bearing — the mode rewrites bag keys AND TED labels,
            // so tier assignment (jaccard vs containment) and edge scores
            // genuinely differ between modes. A numbered cluster set
            // served to an un-numbered run would silently under-report
            // the drifted pairs the mode exists to find.
            $config->fingerprintIdMode,
            // Item 1.2: the TED-size cap decides which jaccard-passing pairs
            // get a real APTED verdict vs a 'jaccard-unverified' edge — edge
            // sets (and thus cluster membership) genuinely differ between
            // caps, so a cluster cache written under one cap must never be
            // served to a run under another.
            $config->maxTedSize,
            // shell-004: the TED cost model rescales every APTED verdict in
            // the jaccard lane — edge scores (and cluster similarity) move
            // between 'default' and 'semantic', so the cluster cache must
            // not serve across models. Same argument as maxTedSize, one
            // notch earlier in the chain. Key-namespace change only: no
            // PARSER_VERSION bump (fingerprintIdMode precedent).
            $config->tedWeights,
            // shell-019: the IR tier and the ML-pair oracle swap the very
            // verdict engine that produced every edge score — buildClusterer
            // (:245-250) and the pair worker (scorePairs) consume all four
            // knobs. A snapshot warmed under --scorer=ir (or a different
            // ir/ml threshold, or a different ml endpoint whose answers
            // shaped the edge set) must never replay into a run wired
            // otherwise: same law as ngram_size above, and the pair-score
            // worker's own batch key already binds all of them — the
            // cluster cache had no business omitting them. Completes the
            // five-knob binding (tedWeights rode in via shell-004).
            // Key-namespace change only: no PARSER_VERSION bump
            // (fingerprintIdMode precedent).
            $config->scorer,
            $config->irThreshold,
            $config->mlPairUrl,
            $config->mlPairThreshold,
            // Item 1.4 (§5b): the medoid-ball split knobs reshape cluster
            // membership, count and reported similarity. Bound to the very
            // constants buildClusterer() hands the Clusterer, so flipping a
            // knob invalidates every cached (pre-split) cluster set.
            Clusterer::DEFAULT_SPLIT_CLUSTERS,
            Clusterer::DEFAULT_MIN_SPLIT_SIZE,
            // Fix-review M-1 (defense in depth, mirroring the IndexStore
            // key): the kind set changes which blocks — hence which corpus
            // tokens and cluster memberships — exist at all. corpus_hash
            // already catches every real divergence (a kinds flip changes
            // the block token list); binding the run's kind set makes the
            // invariant local and explicit instead of an emergent property
            // of the token hash, which matters now that script-kind clusters
            // carry non-relocatable members. Key-namespace change only: no
            // PARSER_VERSION bump (fingerprintIdMode precedent).
            $config->allowedKinds,
        ]));
    }

    /** Yield to the runtime every N edges streamed from the pair-score workers. */
    private const YIELD_EVERY = 256;

    private readonly ProgressListener $listener;

    public function __construct(
        private readonly bool $exactOnly,
        private readonly int $maxMemoryMb = 0,
        ?ProgressListener $listener = null,
        private readonly bool $useClusterCache = true,
    ) {
        $this->listener = $listener ?? new NullProgressListener();
    }

    #[\Override]
    public function name(): Stage
    {
        return Stage::Clustering;
    }

    #[\Override]
    public function run(PipelineState $state, OutputInterface $output): void
    {
        foreach ($this->iter($state, $output) as $_) {
            // synchronous drain
        }
    }

    #[\Override]
    public function iter(PipelineState $state, OutputInterface $output): \Generator
    {
        if (!$state->blocks) {
            return;
        }

        $config = $state->config;

        // Persistent cluster cache: a full-corpus snapshot keyed on
        // sorted (block_id, structuralHash) pairs. Re-runs with no
        // changes hit the cache and skip clustering entirely. Any
        // change invalidates wholesale (the safer choice — partial
        // edge invalidation comes later if it earns its complexity).
        //
        // shell-018 (posture pinned by shell-S30): the snapshot is keyed
        // on the block set as it exists BEFORE expandToCloneCohort()
        // filters it. A --diff-base run carries the full scanned set at
        // this point, so the old load-first path HITTED the warm
        // full-corpus snapshot and replayed clusters from files outside
        // the changed-file cohort — and its miss path SAVED the
        // post-expansion token set, clobbering the shared snapshot under
        // a key no full run reproduces. The cluster cache is therefore
        // not consulted at all under --diff-base; the per-file
        // IndexStore caching upstream is unaffected (it is scope-free by
        // construction).
        $clusterCache = ($this->useClusterCache && $config->incremental && !$this->exactOnly
            && $state->diffBaseFiles === null)
            ? new ClusterCache($config->cacheDir, $this->cacheConfigKey($config))
            : null;
        if ($clusterCache !== null) {
            $cached = $clusterCache->load($state->blocks);
            if ($cached !== null) {
                $state->clusters = $cached;
                // Positions in the replayed objects are only as fresh as the
                // run that cached them: announce the replay so downstream
                // lazy AST anchoring knows the bytes moved on disk since.
                $state->clustersReplayed = true;
                $state->currentTask = sprintf('Reused %d clusters from cache (corpus unchanged)', count($cached));
                $state->stageProgress = 1.0;
                $state->timings['cluster'] = 0.0;
                $output->writeln(sprintf(
                    "<info>phpdup</info> reused %d clusters from cache (corpus unchanged since last run)",
                    count($cached),
                ));
                yield Stage::Clustering;
                return;
            }
        }

        // Build block index with progress reporting
        $state->currentTask = 'Building block index';
        yield Stage::Clustering;

        // Expand the file set to the clone cohort: all files that share
        // n-gram fingerprints with the --diff-base changed files.
        if ($state->diffBaseFiles !== null && $state->diffBaseFiles !== []) {
            $this->expandToCloneCohort($state, $output);
        }

        $index = $this->buildIndex($state, $output);

        // Release ASTs if lazy loading is enabled
        $state->currentTask = 'Releasing source ASTs to free memory';
        if ($config->lazyAst) {
            yield Stage::Clustering;
            $this->unloadAsts($state);
        }

        $tCluster = microtime(true);
        $clusterer = $this->buildClusterer($config);

        if (!$this->exactOnly) {
            // Generate candidate pairs with progress reporting
            $state->currentTask = 'Generating candidate pairs (n-gram inverted index)';
            yield Stage::Clustering;
            $candidatePairs = $this->generateCandidatePairs($clusterer, $index, $state, $output);

            if ($state->candidatePairs > 0) {
                $state->currentTask = $this->chooseScoringTask($config, $state, $output);
                yield Stage::Clustering;

                // scorePairs is a Generator that yields Stage::Clustering for cooperative
                // scheduling. We iterate over it to drive its execution; the generator
                // handles cancellation checks at each yield point internally.
                // Scored edges are stored in $state->edges.
                foreach ($this->scorePairs($candidatePairs, $index, $config, $state, $output) as $_) {
                    // Each yield from scorePairs is Stage::Clustering for TUI repaint.
                    // We consume it here (no additional yield) to avoid doubling.
                    // shell-042 (aborted-run accounting): breaking at this
                    // boundary abandons the remaining pairs, so edges and
                    // scoredPairs can fall short of candidatePairs on an
                    // aborted run. Partial report by design.
                    if ($state->cancelled) {
                        break;
                    }
                }
            }
        }

        // Form clusters with progress reporting
        $state->currentTask = 'Forming clusters from edges';
        $state->stageProgress = 0.97;
        yield Stage::Clustering;
        $this->formClusters($state->edges, $clusterer, $index, $state, $output, $tCluster, $clusterCache);
    }

    private function buildIndex(PipelineState $state, OutputInterface $output): BlockIndex
    {
        $index = new BlockIndex();
        foreach ($state->blocks as $b) {
            $index->add($b);
        }
        $state->index = $index;
        $state->debug($output, sprintf('clustering: built block index with %d blocks [%s]', count($state->blocks), MemoryDebug::getMemoryUsage()));

        return $index;
    }

    private function unloadAsts(PipelineState $state): void
    {
        foreach ($state->blocks as $b) {
            // Fix-review M-1: a 'script' fold's extracted AST IS its only
            // copy — it is synthetic (no parse-tree node), so the loader
            // can never hand one back (its refusal branch is the loud
            // backstop, not a remedy). Unloading a fold therefore destroys
            // it and makes plain `--kinds script` abort at RefactorStage.
            // Folds are small (max_block_size bound) — keeping them loaded
            // is cheap and philosophically right: never unload what can
            // never be reloaded.
            if ($b->kind === 'script') {
                continue;
            }
            $b->unloadAst();
        }
    }

    private function buildClusterer(\Phpdup\Cli\Config $config): Clusterer
    {
        return new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(new EditCostModel($config->tedWeights), $config->fingerprintIdsUnnumbered()),
            similarityThreshold: $config->similarityThreshold,
            treeThreshold: $config->treeThreshold,
            maxDocumentFrequency: $config->maxDocumentFrequency,
            exactOnly: $this->exactOnly,
            optionalBlocksEnabled:    $config->optionalBlocksEnabled,
            containmentThreshold:     $config->optionalBlocksContainment,
            optionalBlocksMinOverlap: $config->optionalBlocksMinOverlap,
            irScoring:                $config->scorer === 'ir',
            irThreshold:              $config->irThreshold,
            mlPairClient:             $config->mlPairUrl !== ''
                ? new \Phpdup\Ml\MlPairClient(baseUrl: $config->mlPairUrl)
                : null,
            mlPairThreshold:          $config->mlPairThreshold,
            // Item 1.4 (§5b): split knobs — same constants the cache
            // config-key binds on, one source of truth.
            splitClusters:            Clusterer::DEFAULT_SPLIT_CLUSTERS,
            minSplitSize:             Clusterer::DEFAULT_MIN_SPLIT_SIZE,
            // phpver-fix: ids are minted scan-relatively; the roots come
            // straight from the run's configured paths.
            scanRoots:                $config->paths,
            // §5a R-4 candidate guard (see Clusterer ctor): live only in
            // un-numbered mode; injects the numbered run's candidate
            // stream so no pair today's run would even consider can be
            // lost to df-cap concentration.
            unnumberedCandidateGuard: $config->fingerprintIdsUnnumbered(),
            candidateGuardNgramSize:  $config->ngramSize,
            // Item 1.2: root-only TED-size cap (config file 'max_ted_size').
            maxTedSize:               $config->maxTedSize,
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function generateCandidatePairs(Clusterer $clusterer, BlockIndex $index, PipelineState $state, OutputInterface $output): array
    {
        $state->currentTask = 'Generating candidate pairs (n-gram inverted index)';

        $state->debug($output, sprintf('clustering: building n-gram inverted index for %d blocks [%s]', count($state->blocks), MemoryDebug::getMemoryUsage()));
        $candidatePairs = iterator_to_array($clusterer->generateCandidatePairs(
            $index,
            $output,
            // Config 0 is the documented "unlimited" sentinel (--candidate-budget 0);
            // any positive value arms the guard at exactly that pair count.
            $state->config->candidateBudget === 0
                ? Clusterer::UNLIMITED_CANDIDATE_PAIRS
                : $state->config->candidateBudget,
        ));
        $state->debug($output, sprintf('clustering: generated %d candidate pairs [%s]', count($candidatePairs), MemoryDebug::getMemoryUsage()));
        $state->candidatePairs = count($candidatePairs);
        $state->scoredPairs    = 0;
        $this->listener->onPairScored(0, $state->candidatePairs);

        return $candidatePairs;
    }

    private function chooseScoringTask(\Phpdup\Cli\Config $config, PipelineState $state, OutputInterface $output): string
    {
        $workers = $config->workers > 0 ? $config->workers : WorkerPool::detectCpuCount();
        $useParallel = $state->candidatePairs >= 64 && $workers > 1 && WorkerPool::isAvailable();
        if ($useParallel) {
            $pairsPerWorker = (int)ceil($state->candidatePairs / $workers);
            $msg = sprintf(
                'clustering: starting parallel scoring: %d pairs across %d workers (~%d pairs/worker) [%s]',
                $state->candidatePairs,
                $workers,
                $pairsPerWorker,
                MemoryDebug::getMemoryUsage(),
            );
        } else {
            $fallbackReason = $workers <= 1
                ? 'workers <= 1'
                : (!WorkerPool::isAvailable() ? 'pcntl not available' : 'candidate pairs < 64');
            $msg = sprintf(
                'clustering: starting serial scoring (%s): %d pairs [%s]',
                $fallbackReason,
                $state->candidatePairs,
                MemoryDebug::getMemoryUsage(),
            );
        }
        $state->debug($output, $msg);

        return $useParallel
            ? sprintf('Scoring %d candidate pairs across %d workers', $state->candidatePairs, $workers)
            : sprintf('Scoring %d candidate pairs', $state->candidatePairs);
    }

    /**
     * Unified scoring driver handling both serial and parallel paths.
     * Yields Stage::Clustering for cooperative scheduling.
     * Results are stored in $state->edges.
     *
     * @param list<array{0: string, 1: string}> $candidatePairs
     */
    private function scorePairs(array $candidatePairs, BlockIndex $index, \Phpdup\Cli\Config $config, PipelineState $state, OutputInterface $output): \Generator
    {
        $workers = $config->workers > 0 ? $config->workers : WorkerPool::detectCpuCount();
        $useParallel = count($candidatePairs) >= 64 && $workers > 1 && WorkerPool::isAvailable();

        $scoreWorker = new PairScoreWorker(
            index: $index,
            similarityThreshold: $config->similarityThreshold,
            treeThreshold: $config->treeThreshold,
            optionalBlocksEnabled: $config->optionalBlocksEnabled,
            containmentThreshold: $config->optionalBlocksContainment,
            optionalBlocksMinOverlap: $config->optionalBlocksMinOverlap,
            irScoring: $config->scorer === 'ir',
            irThreshold: $config->irThreshold,
            mlPairUrl: $config->mlPairUrl,
            mlPairThreshold: $config->mlPairThreshold,
            // Item 1.1 (§5a): the parallel twin must see the exact same
            // label mode the serial Clusterer gets, or clusters fork
            // with worker count (the classic heisenbug class).
            unnumbered: $config->fingerprintIdsUnnumbered(),
            // Item 1.2: same twin law for the TED-size cap — serial and
            // fork-zone scoring must gate the identical pairs identically.
            maxTedSize: $config->maxTedSize,
            // shell-004: same twin law for the TED cost model — before
            // this the fork zone hardcoded 'default' while buildClusterer
            // (above) honoured --ted-weights, so a non-default model made
            // edge scores fork with worker count.
            tedWeights: $config->tedWeights,
        );

        $state->edges = [];
        $sinceYield = 0;
        $lastDebugOutput = microtime(true);
        $tCluster = microtime(true);

        $state->debug($output, sprintf(
            'clustering: scoring began at %s [%s]',
            date('H:i:s'),
            MemoryDebug::getMemoryUsage(),
        ));

        if ($useParallel) {
            $pool = new WorkerPool(workers: $workers);
            $task = static function (array $pairs) use ($scoreWorker): \Generator {
                foreach (array_chunk($pairs, 256) as $chunk) {
                    $scored = $scoreWorker->score($chunk);
                    yield ['__progress' => count($chunk)];
                    foreach ($scored as $edge) {
                        yield $edge;
                    }
                }
            };
            // shell-S12 (shell-041): the pool polls state->cancelled
            // between select wake-ups, so a stall during scoring no
            // longer waits for the next arriving record to abort.
            $cancel = static function () use ($state): bool {
                return $state->cancelled;
            };
            foreach ($pool->runStreaming($candidatePairs, $task, $cancel) as $row) {
                if (is_array($row) && isset($row['__progress'])) {
                    $state->scoredPairs += (int)$row['__progress'];
                } else {
                    /** @var array{0: string, 1: string, 2: float, 3: string} $row */
                    $state->edges[] = $row;
                }
                if (++$sinceYield >= self::YIELD_EVERY) {
                    $sinceYield = 0;
                    // shell-042 (aborted-run accounting): breaking at this
                    // yield boundary abandons the remaining streamed rows;
                    // scoredPairs/edges can fall short of candidatePairs on
                    // an aborted run. Partial report by design.
                    if ($state->cancelled) {
                        break;
                    }
                    $this->updateScoringProgress($state, $output);
                    yield Stage::Clustering;
                }
                $this->emitHeartbeat($state, $output, $tCluster, $lastDebugOutput);
            }
        } else {
            $batchSize = 256;
            foreach (array_chunk($candidatePairs, $batchSize) as $chunk) {
                foreach ($scoreWorker->score($chunk) as $edge) {
                    $state->edges[] = $edge;
                }
                $state->scoredPairs += count($chunk);
                $sinceYield += count($chunk);
                if ($sinceYield >= self::YIELD_EVERY) {
                    $sinceYield = 0;
                    // shell-042 (aborted-run accounting): breaking at this
                    // yield boundary abandons the remaining chunks;
                    // scoredPairs/edges can fall short of candidatePairs on
                    // an aborted run. Partial report by design.
                    if ($state->cancelled) {
                        break;
                    }
                    $this->updateScoringProgress($state, $output);
                    yield Stage::Clustering;
                }
                $this->emitHeartbeat($state, $output, $tCluster, $lastDebugOutput);
            }
        }

        $this->listener->onPairScored($state->candidatePairs, $state->candidatePairs);
    }

    private function updateScoringProgress(PipelineState $state, OutputInterface $output): void
    {
        $denom = max(1, $state->candidatePairs);
        $state->stageProgress = min(0.95, $state->scoredPairs / $denom);
        $state->sampleMemory();
        $this->listener->onPairScored($state->scoredPairs, $state->candidatePairs);
        $state->currentTask = sprintf(
            'Scoring candidate pairs (%d / %d)',
            $state->scoredPairs, $state->candidatePairs,
        );
    }

    private function emitHeartbeat(PipelineState $state, OutputInterface $output, float $tCluster, float &$lastDebugOutput): void
    {
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $now = microtime(true);
            if ($now - $lastDebugOutput >= 5.0) {
                $lastDebugOutput = $now;
                $elapsed = round($now - $tCluster, 1);
                $denom = max(1, $state->candidatePairs);
                $pct = $state->scoredPairs > 0
                    ? sprintf(' (%.1f%%)', 100 * $state->scoredPairs / $denom)
                    : '';
                $state->debug($output, sprintf(
                    'clustering: scoring heartbeat%s | %d / %d pairs | %s elapsed [%s]',
                    $pct,
                    $state->scoredPairs,
                    $state->candidatePairs,
                    $elapsed . 's',
                    MemoryDebug::getMemoryUsage(),
                ));
            }
        }
    }

    /**
     * @param list<array{0: string, 1: string, 2: float, 3: string}> $edges
     */
    private function formClusters(array $edges, Clusterer $clusterer, BlockIndex $index, PipelineState $state, OutputInterface $output, float $tCluster, ?ClusterCache $clusterCache): void
    {
        if ($state->cancelled) {
            return;
        }

        $state->debug($output, sprintf('clustering: forming clusters from %d edges [%s]', count($edges), MemoryDebug::getMemoryUsage()));
        /** @var list<array{0: string, 1: string, 2: float, 3: string}> $edges */
        $state->clusters = $clusterer->cluster($index, $edges);
        $state->timings['cluster'] = microtime(true) - $tCluster;
        $state->currentTask = sprintf('Built %d clusters', count($state->clusters));

        if ($clusterCache !== null) {
            $clusterCache->save($state->blocks, $state->clusters);
        }

        if ($this->maxMemoryMb > 0) {
            $rssMb = (int)floor(memory_get_peak_usage(true) / (1024 * 1024));
            if ($rssMb > $this->maxMemoryMb) {
                $output->writeln(sprintf(
                    "<comment>phpdup: peak RSS %d MB exceeded --max-memory=%d during clustering. Consider --exact-only.</comment>",
                    $rssMb, $this->maxMemoryMb,
                ));
            }
        }
    }

    /**
     * Expand $state->files to include all files that share n-gram
     * fingerprints with the --diff-base changed files (the "clone cohort").
     *
     * This runs before the BlockIndex is built, so we also filter
     * $state->blocks to only include blocks from the expanded file set.
     */
    private function expandToCloneCohort(PipelineState $state, OutputInterface $output): void
    {
        $diffBaseFiles = $state->diffBaseFiles;
        if ($diffBaseFiles === null || $diffBaseFiles === []) {
            return;
        }

        $diffBaseFileSet = array_flip($diffBaseFiles);

        // Collect fingerprints from all blocks in diffBaseFiles.
        // We need the inverted index built from ALL blocks to find
        // files that share fingerprints with these.
        $allBlocks = $state->blocks;
        if ($allBlocks === []) {
            return;
        }

        // Build a temporary inverted index from all blocks.
        // We use a simple approach: for each block from diffBaseFiles,
        // collect its n-grams and find other blocks sharing those n-grams.
        $inverted = new \Phpdup\Index\NgramInvertedIndex();
        // Build the inverted index from a dummy BlockIndex containing all blocks.
        $tmpIndex = new \Phpdup\Index\BlockIndex();
        foreach ($allBlocks as $b) {
            $tmpIndex->add($b);
        }
        $inverted->build($tmpIndex);

        // Find all block IDs that share n-grams with diffBaseFiles blocks.
        $cohortBlockIds = [];
        foreach ($allBlocks as $b) {
            if (!isset($diffBaseFileSet[$b->file])) {
                continue;
            }
            $candidates = $inverted->candidatesFor($b, $state->config->maxDocumentFrequency);
            foreach ($candidates as $candidateId) {
                $cohortBlockIds[$candidateId] = true;
            }
        }

        // Collect all file paths from the candidate blocks.
        $cohortFiles = $diffBaseFiles; // Start with the changed files.
        foreach (array_keys($cohortBlockIds) as $blockId) {
            $block = $tmpIndex->get($blockId);
            if ($block !== null && !isset($diffBaseFileSet[$block->file])) {
                $cohortFiles[] = $block->file;
            }
        }

        // Deduplicate and update $state->files.
        $cohortFiles = array_values(array_unique($cohortFiles));
        $state->files = $cohortFiles;

        // Filter $state->blocks to only include blocks from cohort files.
        $cohortFileSet = array_flip($cohortFiles);
        $filteredBlocks = [];
        foreach ($allBlocks as $b) {
            if (isset($cohortFileSet[$b->file])) {
                $filteredBlocks[] = $b;
            }
        }
        $state->blocks = $filteredBlocks;

        $addedCount = count($cohortFiles) - count($diffBaseFiles);
        $state->debug($output, sprintf(
            'clustering: clone cohort expanded from %d changed files to %d total files (+%d sharing fingerprints)',
            count($diffBaseFiles),
            count($cohortFiles),
            $addedCount,
        ));
        $output->writeln(sprintf(
            "<info>phpdup</info> clone cohort: %d changed + %d related = %s total",
            count($diffBaseFiles),
            $addedCount,
            CountPhrase::files(count($cohortFiles)),
        ));
    }
}
