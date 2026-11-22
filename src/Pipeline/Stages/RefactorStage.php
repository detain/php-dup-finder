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

    #[\Override]
    public function name(): Stage
    {
        return Stage::Refactoring;
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
        if (!$state->clusters) {
            return;
        }

        $config = $state->config;

        $tRefactor = microtime(true);
        $loader = $config->lazyAst
            ? new BlockAstLoader(new AstCache($this->useCache ? $config->cacheDir : ''), new AstParser(), $config)
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

        // [M-2] rebase once, up front, whenever the lazy loader exists.
        // A warm cluster-cache replay hands back member blocks whose line
        // ranges predate any comment/blank drift in the file (structuralHash
        // is comment-invariant, so the cache hit itself is correct by
        // design). The sweep re-anchors every unloaded, content-bound member
        // with the same content-binding + order-preserving law the per-block
        // path enforces — on the PARENT, BEFORE FORKING. Serial and every
        // worker count therefore share ONE deterministic assignment
        // (fork-vs-serial parity by construction), children inherit fresh
        // ranges (their own resolves degrade to no-op strict hits), and the
        // parent never has to trust a child's private positional guess —
        // the wire is scalar-only and the ledger is per-process. A starved
        // content twin throws HERE: before any report render and before
        // --apply writes, so the loud spine still means zero side effects.
        //
        // [m-2] run provenance first: a run that did NOT replay the
        // cluster cache has no stale ranges by construction — every
        // structuralHash and every member line range was minted from the
        // current bytes inside this very process — so the content re-proof
        // (whose whole job is policing replayed-snapshot positions) is
        // switched off for the run via trustFreshScan(), inherited by any
        // forked workers exactly like the sweep's own work. Warm replay
        // never announces and keeps the full law.
        if (!$state->clustersReplayed) {
            $loader?->trustFreshScan();
        }
        $loader?->rebaseClusters($state->clusters, $state->blocks);

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

        // Build an id -> clusters map up front so worker enrichments can be
        // applied back to the parent's Cluster instances in O(1).
        //
        // once-S102 (php-dup-finder-once-127, DET-06): ids are content-
        // derived (Clusterer::contentClusterId) and the stage cannot prove
        // them unique — a hand-built or cache-replayed state may carry the
        // same content at several positions under one id. The map therefore
        // keeps every co-id twin and applies each
        // enrichment record to ALL of them — that is exactly
        // what the positional serial loop does (it enriches every twin it
        // walks past). The previous last-write-wins map silently left the
        // earlier twins raw under fork while serial enriched them, the one
        // real fork-vs-serial merge divergence; the parity tests in
        // tests/Unit/Parallel/RefactorWorkerTest.php pin the broadcast.
        $twinsById = [];
        foreach ($state->clusters as $cluster) {
            $twinsById[$cluster->id][] = $cluster;
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
        // shell-S12 (shell-041): same mid-stream contract as the other two
        // runStreaming callers — the pool sees state->cancelled even while
        // every refactor child is busy (or wedged) and no record arrives.
        $cancel = static function () use ($state): bool {
            return $state->cancelled;
        };
        foreach ($pool->runStreaming($state->clusters, $task, $cancel) as $enrichment) {
            $cid = $enrichment['id'] ?? null;
            if ($cid !== null && isset($twinsById[$cid])) {
                foreach ($twinsById[$cid] as $cluster) {
                    $cluster->generalizedAst = $enrichment['generalizedAst'];
                    $cluster->holes          = $enrichment['holes'];
                    $cluster->holePaths      = $enrichment['holePaths'];
                    $cluster->signature      = $enrichment['signature'];
                    $cluster->patternTags    = $enrichment['patternTags'];
                }
            }

            $state->refactoredClusters++;
            $this->listener->onClusterRefactored($state->refactoredClusters, $total);

            if (++$sinceYield >= self::YIELD_EVERY) {
                $sinceYield = 0;
                // @phpstan-ignore-next-line if.alwaysFalse — SignalHandler sets $state->cancelled on ^C, outside PHPStan's control-flow model
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
        // No post-merge rebasing here: the [M-2] sweep already ran on the
        // parent BEFORE forking (see iter()), so both children and parent
        // started from identical fresh ranges and the merged scalars need
        // no positional repair.
    }
}
