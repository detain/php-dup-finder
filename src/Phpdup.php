<?php
declare(strict_types=1);

namespace Phpdup;

use Phpdup\Cli\Config;
use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fluent-builder facade for invoking phpdup from another PHP app.
 *
 * The CLI is a thin wrapper around the same primitives: ScanningStage,
 * PreprocessStage, ClusterStage, RefactorStage, Ranker. This class is
 * the SDK-friendly surface that hides the stage wiring and returns a
 * Report directly.
 *
 * Example:
 *
 *   $report = (new Phpdup())
 *       ->paths(['src/'])
 *       ->minBlockSize(8)
 *       ->similarity(0.85)
 *       ->minImpact(20)
 *       ->run();
 *
 *   foreach ($report->clusters as $cluster) {
 *       echo $cluster->id, ' impact=', $cluster->impact, "\n";
 *   }
 *
 * The fluent setters are immutable copies so you can fork
 * configurations without mutating shared state.
 */
final class Phpdup
{
    /** @var list<string> */
    private array $paths = [];
    private int $minBlockSize = 8;
    private int $maxBlockSize = 800;
    private string $normalizationMode = 'aggressive';
    private float $similarityThreshold = 0.80;
    private float $treeThreshold = 0.85;
    private int $minClusterImpact = 20;
    private bool $exactOnly = false;
    private bool $useCache = true;
    private bool $lazyAst = true;
    private int $workers = 0;

    /** @param list<string> $paths */
    public function paths(array $paths): self
    {
        $clone = clone $this;
        $clone->paths = array_values($paths);
        return $clone;
    }

    public function minBlockSize(int $n): self
    {
        $clone = clone $this;
        $clone->minBlockSize = $n;
        return $clone;
    }

    public function maxBlockSize(int $n): self
    {
        $clone = clone $this;
        $clone->maxBlockSize = $n;
        return $clone;
    }

    public function normalization(string $mode): self
    {
        $clone = clone $this;
        $clone->normalizationMode = $mode;
        return $clone;
    }

    public function similarity(float $jaccardThreshold): self
    {
        $clone = clone $this;
        $clone->similarityThreshold = $jaccardThreshold;
        return $clone;
    }

    public function treeThreshold(float $tedThreshold): self
    {
        $clone = clone $this;
        $clone->treeThreshold = $tedThreshold;
        return $clone;
    }

    public function minImpact(int $n): self
    {
        $clone = clone $this;
        $clone->minClusterImpact = $n;
        return $clone;
    }

    public function exactOnly(bool $on = true): self
    {
        $clone = clone $this;
        $clone->exactOnly = $on;
        return $clone;
    }

    public function workers(int $n): self
    {
        $clone = clone $this;
        $clone->workers = $n;
        return $clone;
    }

    public function noCache(): self
    {
        $clone = clone $this;
        $clone->useCache = false;
        return $clone;
    }

    public function noLazyAst(): self
    {
        $clone = clone $this;
        $clone->lazyAst = false;
        return $clone;
    }

    /**
     * Run the pipeline and return the final Report. Output goes to a
     * NullOutput by default; pass a custom OutputInterface to surface
     * progress messages.
     */
    public function run(?OutputInterface $output = null): Report
    {
        $output ??= new NullOutput();

        $config = new Config(
            paths:               $this->paths,
            exclude:             Config::defaults($this->paths)->exclude,
            minBlockSize:        $this->minBlockSize,
            maxBlockSize:        $this->maxBlockSize,
            normalizationMode:   $this->normalizationMode,
            similarityThreshold: $this->similarityThreshold,
            treeThreshold:       $this->treeThreshold,
            minClusterImpact:    $this->minClusterImpact,
            workers:             $this->workers,
            lazyAst:             $this->lazyAst,
        );

        $state = new PipelineState($config);
        $pipeline = new Pipeline(stages: [
            new ScanningStage(),
            new PreprocessStage(useCache: $this->useCache),
            new ClusterStage(exactOnly: $this->exactOnly),
            new RefactorStage(useCache: $this->useCache),
        ]);
        $pipeline->run($state, $output);

        $clusters = (new Ranker($config->minClusterImpact))->rank($state->clusters);
        return new Report(
            files:       count($state->files),
            blocks:      count($state->blocks),
            parseErrors: $state->parseErrors,
            clusters:    $clusters,
            config:      $config,
        );
    }
}
