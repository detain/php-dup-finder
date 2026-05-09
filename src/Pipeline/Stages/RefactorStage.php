<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Extraction\BlockAstLoader;
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
        $antiUnifier = new AntiUnifier(
            $loader,
            $config->optionalBlocksEnabled,
            $config->optionalBlocksMaxPerCluster,
            $config->optionalBlocksMinSegmentLength,
        );
        $synth       = new ParameterSynthesizer();
        $sigBuilder  = new SignatureBuilder();
        $patterns    = new PatternRecognizer();

        $total = count($state->clusters);
        $state->refactoredClusters = 0;
        $state->currentTask = sprintf('Anti-unifying %d clusters', $total);
        yield Stage::Refactoring;

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
        $state->timings['refactor'] = microtime(true) - $tRefactor;
        $state->currentTask = sprintf('Refactored %d clusters', $total);
    }
}
