<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Clustering\Clusterer;
use Phpdup\Index\BlockIndex;
use Phpdup\Parallel\PairScoreWorker;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\StageInterface;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;
use Symfony\Component\Console\Output\OutputInterface;

final class ClusterStage implements StageInterface
{
    public function __construct(
        private readonly bool $exactOnly,
        private readonly int $maxMemoryMb = 0,
    ) {}

    public function name(): Stage
    {
        return Stage::Clustering;
    }

    public function run(PipelineState $state, OutputInterface $output): void
    {
        if (!$state->blocks) {
            return;
        }

        $config = $state->config;

        $index = new BlockIndex();
        foreach ($state->blocks as $b) {
            $index->add($b);
        }
        $state->index = $index;

        // Optional: drop original ASTs to free memory; reload lazily during refactor.
        if ($config->lazyAst) {
            foreach ($state->blocks as $b) {
                $b->unloadAst();
            }
        }

        $tCluster = microtime(true);
        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: $config->similarityThreshold,
            treeThreshold: $config->treeThreshold,
            maxDocumentFrequency: $config->maxDocumentFrequency,
            exactOnly: $this->exactOnly,
            optionalBlocksEnabled:    $config->optionalBlocksEnabled,
            containmentThreshold:     $config->optionalBlocksContainment,
            optionalBlocksMinOverlap: $config->optionalBlocksMinOverlap,
        );

        $edges = null;
        if (!$this->exactOnly) {
            $candidatePairs = $clusterer->generateCandidatePairs($index);
            $workers = $config->workers > 0 ? $config->workers : WorkerPool::detectCpuCount();
            if (count($candidatePairs) >= 64 && $workers > 1) {
                $scoreWorker = new PairScoreWorker(
                    $index,
                    $config->similarityThreshold,
                    $config->treeThreshold,
                    $config->optionalBlocksEnabled,
                    $config->optionalBlocksContainment,
                    $config->optionalBlocksMinOverlap,
                );
                $pool = new WorkerPool(workers: $workers);
                $task = static fn(array $pairs): array => $scoreWorker->score($pairs);
                $edges = $pool->run($candidatePairs, $task);
            }
        }
        $state->clusters = $clusterer->cluster($index, $edges);
        $state->timings['cluster'] = microtime(true) - $tCluster;

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
