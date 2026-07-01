<?php
declare(strict_types=1);

namespace Phpdup\Pipeline;

use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Reporting\Report;
use Symfony\Component\Console\Output\OutputInterface;

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

    /**
     * Files changed in the git diff range (set by ScanningStage when
     * --diff-base is provided). Used by ClusterStage to compute the
     * "clone cohort" — files sharing n-gram fingerprints with these.
     * @var list<string>|null
     */
    public ?array $diffBaseFiles = null;

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
    /** @var list<array{0: string, 1: string, 2: float, 3: string}> Scored edge pairs from clustering. */
    public array $edges = [];
    /** Clusters processed by anti-unification (Refactoring stage). */
    public int $refactoredClusters = 0;

    /** Short, human-friendly description of what the active stage is doing right now. */
    public string $currentTask = '';

    public ?Report $report = null;

    /**
     * Soft-cancel flag: set by SignalHandler when the user presses ^C
     * during a long run. Cooperative stages MUST consult this between
     * yields and break out cleanly so the pipeline can produce a
     * partial report instead of dropping out via SIGINT.
     */
    public bool $cancelled = false;

    /** Ring buffer of recent debug messages. */
    public const DEBUG_BUFFER_SIZE = 100;
    /** @var list<string> */
    public array $debugMessages = [];
    public int $debugIndex = 0;

    /** Current RSS and peak RSS in bytes (updated on each stage yield). */
    public int $rssBytes = 0;
    public int $peakBytes = 0;

    /** Per-stage elapsed time tracking for TUI display. */
    public float $stageStartTime = 0.0;

    /**
     * Error message set by ScanningStage when git diff --diff-base fails.
     * When non-null, dispatch() in Command.php returns exit code 2.
     */
    public ?string $scanError = null;

    /** @var array<string,float> stage-name → seconds */
    public array $timings = [
        'preprocess' => 0.0,
        'cluster'    => 0.0,
        'refactor'   => 0.0,
    ];

    private ?DebugLogger $debugLogger = null;

    public function __construct(public readonly Config $config) {}

    public function setDebugLogger(DebugLogger $logger): void
    {
        $this->debugLogger = $logger;
    }

    /**
     * Push a debug message to the ring buffer and optionally to the debug log file.
     */
    public function pushDebugMessage(string $message): void
    {
        $this->debugMessages[$this->debugIndex % self::DEBUG_BUFFER_SIZE] = $message;
        $this->debugIndex++;
        $this->debugLogger?->append($message);
    }

    /**
     * Sample current and peak memory usage into the state fields.
     */
    public function sampleMemory(): void
    {
        $this->rssBytes = memory_get_usage(false);
        $this->peakBytes = memory_get_peak_usage(true);
    }

    /**
     * Emit a debug message to output and the ring buffer when verbosity is DEBUG.
     *
     * @param OutputInterface $output
     * @param string          $message
     */
    public function debug(OutputInterface $output, string $message): void
    {
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln($message);
            $this->pushDebugMessage($message);
        }
    }
}
