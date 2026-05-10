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
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $msg = sprintf('clustering: built block index with %d blocks [%s]', count($state->blocks), MemoryDebug::getMemoryUsage());
            $output->writeln($msg);
            $state->pushDebugMessage($msg);
        }

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

            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $msg = sprintf('clustering: building n-gram inverted index for %d blocks [%s]', count($state->blocks), MemoryDebug::getMemoryUsage());
                $output->writeln($msg);
                $state->pushDebugMessage($msg);
            }

            // Progress callback for enumeration phase - updates TUI state and reports progress
            $tCluster = microtime(true);
            $enumProgressCallback = static function (int $blockNum, int $totalBlocks, int $pairCount) use ($state, $output, $tCluster): void {
                $state->currentTask = sprintf(
                    'Enumerating candidate pairs (%d / %d blocks, %d pairs found)',
                    $blockNum,
                    $totalBlocks,
                    $pairCount,
                );
                $state->stageProgress = $blockNum / $totalBlocks;
                $state->rssBytes = memory_get_usage(false);
                $state->peakBytes = memory_get_peak_usage(true);

                // Always output at DEBUG verbosity to show enumeration progress
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    $elapsed = round(microtime(true) - $tCluster, 1);
                    $msg = sprintf(
                        'clustering: enumeration | %d / %d blocks | %d candidate pairs | %s elapsed [%s]',
                        $blockNum,
                        $totalBlocks,
                        $pairCount,
                        $elapsed . 's',
                        MemoryDebug::getMemoryUsage(),
                    );
                    $output->writeln($msg);
                    $state->pushDebugMessage($msg);
                }
            };

            $workers = $config->workers > 0 ? $config->workers : WorkerPool::detectCpuCount();

            // Parallel enumeration is temporarily disabled due to null ID issue in partitioned processing.
            // TODO: Re-enable when fixed
            // if ($workers > 1 && WorkerPool::isAvailable()) {
            //     if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            //         $output->writeln(sprintf(
            //             'clustering: using parallel enumeration across %d workers [%s]',
            //             $workers,
            //             MemoryDebug::getMemoryUsage(),
            //         ));
            //     }
            //     $candidatePairs = $clusterer->generateCandidatePairsParallel($index, $workers, $output, $enumProgressCallback);
            // } else {
            //     $candidatePairs = $clusterer->generateCandidatePairs($index, $output, $enumProgressCallback);
            // }
            $candidatePairs = $clusterer->generateCandidatePairs($index, $output, $enumProgressCallback);

            // For parallel: we stream by buffering pairs into chunks for dispatch.
            // For serial: we use the generator directly with array_chunk().
            $useParallel = $workers > 1 && WorkerPool::isAvailable();

            if ($useParallel) {
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    $msg = sprintf(
                        'clustering: streaming candidate pairs to parallel workers [%s]',
                        MemoryDebug::getMemoryUsage(),
                    );
                    $output->writeln($msg);
                    $state->pushDebugMessage($msg);
                }
                $state->currentTask = sprintf('Scoring candidate pairs across %d workers', $workers);
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

                // Streaming parallel: buffer pairs into chunks, dispatch to worker pool,
                // and yield results immediately — never materializing all 22M pairs.
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

                $edges = [];
                $sinceYield = 0;
                $lastDebugOutput = microtime(true);
                $pairBufferSize = 10000;  // Buffer 10000 pairs per dispatch to worker pool
                $bufferedPairs = [];

                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    $msg = sprintf(
                        'clustering: scoring began at %s [%s]',
                        date('H:i:s'),
                        MemoryDebug::getMemoryUsage(),
                    );
                    $output->writeln($msg);
                    $state->pushDebugMessage($msg);
                }

                foreach ($candidatePairs as $pair) {
                    $bufferedPairs[] = $pair;
                    if (count($bufferedPairs) >= $pairBufferSize) {
                        // Dispatch buffered chunk and stream results
                        foreach ($pool->runStreaming($bufferedPairs, $task) as $row) {
                            if (is_array($row) && isset($row['__progress'])) {
                                $state->scoredPairs += (int)$row['__progress'];
                            } else {
                                $edges[] = $row;
                            }
                            if (++$sinceYield >= self::YIELD_EVERY) {
                                $sinceYield = 0;
                                $state->stageProgress = 0.5;  // Unknown total, use 50% as placeholder
                                $state->rssBytes = memory_get_usage(false);
                                $state->peakBytes = memory_get_peak_usage(true);
                                $this->listener->onPairScored($state->scoredPairs, 0);
                                $state->currentTask = sprintf(
                                    'Scoring candidate pairs (%d scored)',
                                    $state->scoredPairs,
                                );
                                yield Stage::Clustering;
                            }
                        }
                        $bufferedPairs = [];
                    }
                }
                // Flush remaining buffered pairs
                if ($bufferedPairs !== []) {
                    foreach ($pool->runStreaming($bufferedPairs, $task) as $row) {
                        if (is_array($row) && isset($row['__progress'])) {
                            $state->scoredPairs += (int)$row['__progress'];
                        } else {
                            $edges[] = $row;
                        }
                    }
                }

                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    $elapsed = round(microtime(true) - $tCluster, 1);
                    $msg = sprintf(
                        'clustering: scoring complete | %d pairs scored | %s elapsed [%s]',
                        $state->scoredPairs,
                        $elapsed . 's',
                        MemoryDebug::getMemoryUsage(),
                    );
                    $output->writeln($msg);
                    $state->pushDebugMessage($msg);
                }
                $this->listener->onPairScored($state->scoredPairs, $state->scoredPairs);
            } else {
                // Serial path: use generator directly with array_chunk
                $state->currentTask = 'Scoring candidate pairs';
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
                $sinceYield = 0;
                $lastDebugOutput = microtime(true);
                $batchSize = 256;
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    $msg = sprintf(
                        'clustering: serial scoring began at %s [%s]',
                        date('H:i:s'),
                        MemoryDebug::getMemoryUsage(),
                    );
                    $output->writeln($msg);
                    $state->pushDebugMessage($msg);
                }
                // Stream pairs from generator, accumulating into batches for scoring.
                // Avoids array_chunk() on Generator which PHPStan doesn't recognize.
                $chunk = [];
                foreach ($candidatePairs as $pair) {
                    $chunk[] = $pair;
                    if (count($chunk) >= $batchSize) {
                        $chunkSize = count($chunk);
                        foreach ($scoreWorker->score($chunk) as $edge) {
                            $edges[] = $edge;
                        }
                        $state->scoredPairs += $chunkSize;
                        $chunk = [];
                        // Each batch is exactly YIELD_EVERY size, so we yield after each batch.
                        $state->stageProgress = 0.5;  // Unknown total, placeholder
                        $state->rssBytes = memory_get_usage(false);
                        $state->peakBytes = memory_get_peak_usage(true);
                        $this->listener->onPairScored($state->scoredPairs, 0);
                        $state->currentTask = sprintf(
                            'Scoring candidate pairs (%d scored)',
                            $state->scoredPairs,
                        );
                        yield Stage::Clustering;
                        // Heartbeat: debug output every 5 seconds so user sees progress
                        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                            $now = microtime(true);
                            if ($now - $lastDebugOutput >= 5.0) {
                                $lastDebugOutput = $now;
                                $elapsed = round($now - $tCluster, 1);
                                $msg = sprintf(
                                    'clustering: scoring heartbeat | %d pairs scored | %s elapsed [%s]',
                                    $state->scoredPairs,
                                    $elapsed . 's',
                                    MemoryDebug::getMemoryUsage(),
                                );
                                $output->writeln($msg);
                                $state->pushDebugMessage($msg);
                            }
                        }
                    }
                }
                // Flush any remaining pairs in the final chunk
                if ($chunk !== []) {
                    foreach ($scoreWorker->score($chunk) as $edge) {
                        $edges[] = $edge;
                    }
                    $state->scoredPairs += count($chunk);
                }
                $this->listener->onPairScored($state->scoredPairs, $state->scoredPairs);
            }
        }

        $state->currentTask = 'Forming clusters from edges';
        $state->stageProgress = 0.97;
        yield Stage::Clustering;

        // Strip __progress bookkeeping rows injected by the streaming score path.
        /** @var list<array{0: string, 1: string, 2: float}> $pureEdges */
        $pureEdges = [];
        foreach ($edges ?? [] as $e) {
            if (!isset($e['__progress'])) {
                /** @var array{0: string, 1: string, 2: float} $e */
                $pureEdges[] = $e;
            }
        }
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $msg = sprintf('clustering: forming clusters from %d edges [%s]', count($pureEdges), MemoryDebug::getMemoryUsage());
            $output->writeln($msg);
            $state->pushDebugMessage($msg);
        }
        $state->clusters = $clusterer->cluster($index, $pureEdges);
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
