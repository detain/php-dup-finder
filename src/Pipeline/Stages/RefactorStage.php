<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Extraction\BlockAstLoader;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\StageInterface;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\ParameterSynthesizer;
use Phpdup\Refactor\PatternRecognizer;
use Phpdup\Refactor\SignatureBuilder;
use Symfony\Component\Console\Output\OutputInterface;

final class RefactorStage implements StageInterface
{
    public function __construct(private readonly bool $useCache) {}

    public function name(): Stage
    {
        return Stage::Refactoring;
    }

    public function run(PipelineState $state, OutputInterface $output): void
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

        foreach ($state->clusters as $cluster) {
            $antiUnifier->unify($cluster);
            $synth->synthesize($cluster);
            $sigBuilder->buildSignature($cluster);
            $patterns->tag($cluster);
        }
        $state->timings['refactor'] = microtime(true) - $tRefactor;
    }
}
