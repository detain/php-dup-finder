<?php
declare(strict_types=1);

namespace Phpdup\Pipeline;

use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Reporting\Report;

/**
 * Mutable pipeline state shared across stages.
 *
 * Stages read inputs from prior stages here and write their outputs.
 * The TUI reads progress fields ($stage, $stageProgress, $scannedFiles, ...) to render updates.
 */
final class PipelineState
{
    public Stage $stage = Stage::Scanning;

    /** Progress within the current stage, 0.0 .. 1.0. */
    public float $stageProgress = 0.0;

    /** @var list<string> Absolute file paths discovered by ScanningStage. */
    public array $files = [];
    public int $totalFiles = 0;
    public int $scannedFiles = 0;

    /** @var list<Block> Extracted blocks after PreprocessStage. */
    public array $blocks = [];
    public ?BlockIndex $index = null;
    public int $parseErrors    = 0;
    public int $reusedFiles    = 0;
    public int $processedFiles = 0;

    /** @var list<Cluster> Final clusters after RefactorStage. */
    public array $clusters = [];

    /** Total candidate pairs queued for scoring (Clustering stage). */
    public int $candidatePairs = 0;
    /** Pairs scored so far (Clustering stage). */
    public int $scoredPairs = 0;
    /** Clusters processed by anti-unification (Refactoring stage). */
    public int $refactoredClusters = 0;

    /** Short, human-friendly description of what the active stage is doing right now. */
    public string $currentTask = '';

    public ?Report $report = null;

    /** @var array<string,float> stage-name → seconds */
    public array $timings = [
        'preprocess' => 0.0,
        'cluster'    => 0.0,
        'refactor'   => 0.0,
    ];

    public function __construct(public readonly Config $config) {}
}
