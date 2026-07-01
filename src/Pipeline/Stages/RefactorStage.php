<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Extraction\BlockAstLoader;
use Phpdup\Parallel\RefactorWorker;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use Phpdup\Pipeline\CooperativeStageInterface;
use Phpdup\Pipeline\NullProgressListener;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\ProgressListener;
use Phpdup\Pipeline\Stage;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\ParameterSynthesizer;
use Phpdup\Refactor\PatternRecognizer;
use Phpdup\Refactor\SignatureBuilder;
use Symfony\Component\Console\Output\OutputInterface;

final class RefactorStage implements CooperativeStageInterface
{
    /** Yield every N clusters refactored so the TUI can repaint. */
    private const YIELD_EVERY = 4;

    /** Minimum cluster count before parallel refactor is worth the fork overhead. */
    private const PARALLEL_MIN_CLUSTERS = 8;

    private readonly ProgressListener $listener;

    public function __construct(
        private readonly bool $useCache,
        ?ProgressListener $listener = null,
    ) {
        $this->listener = $listener ?? new NullProgressListener();
    }

    public function name(): Stage
    {
        return Stage::Refactoring;
    }

    public function run(PipelineState $state, OutputInterface $output): void
    {
        foreach ($this->iter($state, $output) as $_) {
            // synchronous drain
        }
    }

    public function iter(PipelineState $state, OutputInterface $output): \Generator
    {
        if (!$state->clusters) {
            return;
        }

        $config = $state->config;

        $tRefactor = microtime(true);
        $loader = $config->lazyAst
            ? new BlockAstLoader(new AstCache($this->useCache ? $config->cacheDir : ''), new AstParser())
            : null;

        $total = count($state->clusters);
        $state->refactoredClusters = 0;
        $state->currentTask = sprintf('Anti-unifying %d clusters', $total);
        if ($state->cancelled) {
            return;
        }
        yield Stage::Refactoring;

        $workers     = $config->workers > 0 ? $config->workers : WorkerPool::detectCpuCount();
        $useParallel = $total >= self::PARALLEL_MIN_CLUSTERS
            && $workers > 1
            && WorkerPool::isAvailable();

        if ($useParallel) {
            yield from $this->iterParallel($state, $loader, $workers, $total);
        } else {
            yield from $this->iterSerial($state, $loader, $total);
        }

        $state->timings['refactor'] = microtime(true) - $tRefactor;
        $state->currentTask = sprintf('Refactored %d clusters', $total);
    }

    private function iterSerial(PipelineState $state, ?BlockAstLoader $loader, int $total): \Generator
    {
        $config = $state->config;
        $antiUnifier = new AntiUnifier(
            $loader,
            $config->optionalBlocksEnabled,
            $config->optionalBlocksMaxPerCluster,
            $config->optionalBlocksMinSegmentLength,
        );
        $synth      = new ParameterSynthesizer();
        $sigBuilder = new SignatureBuilder();
        $patterns   = new PatternRecognizer();

        $sinceYield = 0;
        foreach ($state->clusters as $i => $cluster) {
            $antiUnifier->unify($cluster);
            $synth->synthesize($cluster);
            $sigBuilder->buildSignature($cluster);
            $patterns->tag($cluster);

            $state->refactoredClusters = $i + 1;
            $this->listener->onClusterRefactored($state->refactoredClusters, $total);

            if (++$sinceYield >= self::YIELD_EVERY) {
                $sinceYield = 0;
                if ($state->cancelled) {
                    break;
                }
                $state->stageProgress = $total > 0
                    ? min(0.99, $state->refactoredClusters / $total)
                    : 0.0;
                $state->currentTask = sprintf(
                    'Refactoring clusters (%d / %d)',
                    $state->refactoredClusters, $total,
                );
                yield Stage::Refactoring;
            }
        }
    }

    private function iterParallel(PipelineState $state, ?BlockAstLoader $loader, int $workers, int $total): \Generator
    {
        $config = $state->config;
        $state->currentTask = sprintf(
            'Refactoring %d clusters across %d workers', $total, $workers,
        );
        if ($state->cancelled) {
            return;
        }
        yield Stage::Refactoring;

        // Build an id -> cluster map up front so worker enrichments can be
        // applied back to the parent's Cluster instances in O(1).
        $byId = [];
        foreach ($state->clusters as $cluster) {
            $byId[$cluster->id] = $cluster;
        }

        $worker = new RefactorWorker(
            $loader,
            $config->optionalBlocksEnabled,
            $config->optionalBlocksMaxPerCluster,
            $config->optionalBlocksMinSegmentLength,
        );

        $task = static function (array $clusters) use ($worker): \Generator {
            foreach (array_chunk($clusters, 4) as $chunk) {
                foreach ($worker->process($chunk) as $enrichment) {
                    yield $enrichment;
                }
            }
        };

        $pool       = new WorkerPool(workers: $workers);
        $sinceYield = 0;
        foreach ($pool->runStreaming($state->clusters, $task) as $enrichment) {
            $cid = $enrichment['id'] ?? null;
            if ($cid !== null && isset($byId[$cid])) {
                $cluster = $byId[$cid];
                $cluster->generalizedAst = $enrichment['generalizedAst'];
                $cluster->holes          = $enrichment['holes'];
                $cluster->holePaths      = $enrichment['holePaths'];
                $cluster->signature      = $enrichment['signature'];
                $cluster->patternTags    = $enrichment['patternTags'];
            }

            $state->refactoredClusters++;
            $this->listener->onClusterRefactored($state->refactoredClusters, $total);

            if (++$sinceYield >= self::YIELD_EVERY) {
                $sinceYield = 0;
                if ($state->cancelled) {
                    break;
                }
                $state->stageProgress = $total > 0
                    ? min(0.99, $state->refactoredClusters / $total)
                    : 0.0;
                $state->currentTask = sprintf(
                    'Refactoring clusters (%d / %d)',
                    $state->refactoredClusters, $total,
                );
                yield Stage::Refactoring;
            }
        }
    }
}
