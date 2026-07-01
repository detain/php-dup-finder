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

    public function name(): Stage
    {
        return Stage::Clustering;
    }

    public function run(PipelineState $state, OutputInterface $output): void
    {
        foreach ($this->iter($state, $output) as $_) {
            // synchronous drain
        }
    }

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
        $clusterCache = ($this->useClusterCache && $config->incremental && !$this->exactOnly)
            ? new ClusterCache($config->cacheDir, $this->cacheConfigKey($config))
            : null;
        if ($clusterCache !== null) {
            $cached = $clusterCache->load($state->blocks);
            if ($cached !== null) {
                $state->clusters = $cached;
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

        $state->currentTask = 'Building block index';
        yield Stage::Clustering;

        $index = new BlockIndex();
        foreach ($state->blocks as $b) {
            $index->add($b);
        }
        $state->index = $index;
        $state->debug($output, sprintf('clustering: built block index with %d blocks [%s]', count($state->blocks), MemoryDebug::getMemoryUsage()));

        // Optional: drop original ASTs to free memory; reload lazily during refactor.
        if ($config->lazyAst) {
            $state->currentTask = 'Releasing source ASTs to free memory';
            yield Stage::Clustering;
            foreach ($state->blocks as $b) {
                $b->unloadAst();
            }
        }

        $tCluster = microtime(true);
        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(new EditCostModel($config->tedWeights)),
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
        );

        $edges = null;
        if (!$this->exactOnly) {
            $state->currentTask = 'Generating candidate pairs (n-gram inverted index)';
            yield Stage::Clustering;

            $state->debug($output, sprintf('clustering: building n-gram inverted index for %d blocks [%s]', count($state->blocks), MemoryDebug::getMemoryUsage()));
            $candidatePairs = iterator_to_array($clusterer->generateCandidatePairs($index, $output));
            $state->debug($output, sprintf('clustering: generated %d candidate pairs [%s]', count($candidatePairs), MemoryDebug::getMemoryUsage()));
            $state->candidatePairs = count($candidatePairs);
            $state->scoredPairs    = 0;
            $this->listener->onPairScored(0, $state->candidatePairs);

            if ($state->candidatePairs > 0) {
                $workers = $config->workers > 0 ? $config->workers : WorkerPool::detectCpuCount();
                $useParallel = $state->candidatePairs >= 64 && $workers > 1;
                if ($useParallel && WorkerPool::isAvailable()) {
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
                $state->currentTask = $useParallel && WorkerPool::isAvailable()
                    ? sprintf('Scoring %d candidate pairs across %d workers', $state->candidatePairs, $workers)
                    : sprintf('Scoring %d candidate pairs', $state->candidatePairs);
                yield Stage::Clustering;

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
                );

                $edges = [];
                if ($useParallel && WorkerPool::isAvailable()) {
                    $pool = new WorkerPool(workers: $workers);
                    $task = static function (array $pairs) use ($scoreWorker): \Generator {
                        // Sub-batch so the parent receives multiple frames per child,
                        // letting the TUI repaint while pairs are still being scored.
                        foreach (array_chunk($pairs, 256) as $chunk) {
                            $scored = $scoreWorker->score($chunk);
                            yield ['__progress' => count($chunk)];
                            foreach ($scored as $edge) {
                                yield $edge;
                            }
                        }
                    };
                    $sinceYield = 0;
                    $lastDebugOutput = microtime(true);
                    $state->debug($output, sprintf(
                        'clustering: scoring began at %s [%s]',
                        date('H:i:s'),
                        MemoryDebug::getMemoryUsage(),
                    ));
                    foreach ($pool->runStreaming($candidatePairs, $task) as $row) {
                        if (is_array($row) && isset($row['__progress'])) {
                            $state->scoredPairs += (int)$row['__progress'];
                        } else {
                            $edges[] = $row;
                        }
                        if (++$sinceYield >= self::YIELD_EVERY) {
                            $sinceYield = 0;
                            $denom = max(1, $state->candidatePairs);
                            $state->stageProgress = min(0.95, $state->scoredPairs / $denom);
                            $state->sampleMemory();
                            $this->listener->onPairScored($state->scoredPairs, $state->candidatePairs);
                            $state->currentTask = sprintf(
                                'Scoring candidate pairs (%d / %d)',
                                $state->scoredPairs, $state->candidatePairs,
                            );
                            yield Stage::Clustering;
                        }
                        // Heartbeat: debug output every 5 seconds so user sees progress
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
                } else {
                    // Serial path with periodic yields so the TUI stays responsive.
                    $sinceYield = 0;
                    $lastDebugOutput = microtime(true);
                    $batchSize  = 256;
                    $state->debug($output, sprintf(
                        'clustering: serial scoring began at %s [%s]',
                        date('H:i:s'),
                        MemoryDebug::getMemoryUsage(),
                    ));
                    foreach (array_chunk($candidatePairs, $batchSize) as $chunk) {
                        foreach ($scoreWorker->score($chunk) as $edge) {
                            $edges[] = $edge;
                        }
                        $state->scoredPairs += count($chunk);
                        $sinceYield += count($chunk);
                        if ($sinceYield >= self::YIELD_EVERY) {
                            $sinceYield = 0;
                            $denom = max(1, $state->candidatePairs);
                            $state->stageProgress = min(0.95, $state->scoredPairs / $denom);
                            $state->sampleMemory();
                            $this->listener->onPairScored($state->scoredPairs, $state->candidatePairs);
                            $state->currentTask = sprintf(
                                'Scoring candidate pairs (%d / %d)',
                                $state->scoredPairs, $state->candidatePairs,
                            );
                            yield Stage::Clustering;
                        }
                        // Heartbeat: debug output every 5 seconds so user sees progress
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
                }
                $this->listener->onPairScored($state->candidatePairs, $state->candidatePairs);
            }
        }

        $state->currentTask = 'Forming clusters from edges';
        $state->stageProgress = 0.97;
        yield Stage::Clustering;

        $state->debug($output, sprintf('clustering: forming clusters from %d edges [%s]', count($edges ?? []), MemoryDebug::getMemoryUsage()));
        /** @var list<array{0: string, 1: string, 2: float}> $edges */
        $edges = $edges ?? [];
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
}
