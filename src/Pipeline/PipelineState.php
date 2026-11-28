<?php
declare(strict_types=1);

namespace Phpdup\Pipeline;

use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Index\SequenceIndex;
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

    /**
     * Item 2.1 §5c: the ONE sequence store built from PreprocessWorker's
     * `mined` rows (statement-hash lists per executable statement list).
     * Null unless a consumer armed the miner (v1: 'fragment' in
     * allowedKinds; 3.x census legs add their own demands).
     *
     * Partial-on-warm law: cache-reused files emit no rows, so this index
     * is corpus-complete only on cold runs. Consumers that need
     * warm-completeness must add their own demand/reprocess leg — the
     * fragment suppression deliberately does NOT read this index (it
     * reads the persisted Block fields), which is what makes cold and
     * warm cluster output identical.
     */
    public ?SequenceIndex $sequenceIndex = null;
    public int $parseErrors    = 0;
    public int $reusedFiles    = 0;
    public int $processedFiles = 0;
    /** Fresh (non-reused) files that parsed but yielded zero blocks (0.6). */
    public int $skippedFiles   = 0;

    /**
     * Item 0.6 (oversized leg): blocks the extractor's max_block_size
     * ceiling gave up on during THIS run's FRESH extractions — nodes
     * above the cap from which no scan window was salvaged (with
     * window_oversized off, every oversized node). Block-level count,
     * orthogonal to the file buckets: it never enters the
     * scanned = reused + processed + no-blocks + parse-errors identity.
     * Fresh-only semantics: cache-reused files never re-extract, so their
     * historical drops are not recounted (same run-scope honesty as
     * $skippedFiles, which only counts fresh files too by construction).
     */
    public int $oversizedBlocks = 0;

    /**
     * Honest scan signals (once-S37 / php-dup-finder-once-041): every note
     * FileScanner recorded for a path the scan could not use — unreadable or
     * missing roots and files, symlink-containment refusals, undecidable
     * patterns — plus one exclude-typo warning per inert user pattern. This
     * is the shared channel plan §37 books for later consumers (S41/S66/S71
     * ride it; the JSON summary fields land with the schema-1.8 carrier
     * threading). Snapshot by ScanningStage after each logical scan.
     *
     * @var list<string>
     */
    public array $scanNotes = [];

    /**
     * Files the preprocess workers could not parse, each with the REAL
     * parser message (once-S37 / php-dup-finder-once-080): the count rides
     * $parseErrors, but a count alone laundered WHICH file and WHY.
     * PreprocessStage appends one record per worker error row; --verbose
     * lists them, and the JSON summary fields ride the same carrier leg as
     * $scanNotes above.
     *
     * @var list<array{file: string, message: string}>
     */
    public array $parseFailures = [];

    /** @var list<Cluster> Final clusters after RefactorStage. */
    public array $clusters = [];

    /**
     * True iff ClusterStage loaded the whole cluster set from the
     * persistent cluster cache. Cached Cluster objects carry the line
     * ranges they had when the snapshot was written, so this flag is the
     * ONE honest signal that member positions may be stale: a fresh
     * (non-replayed) scan mints every hash and every range from the
     * current bytes of this very run, and the refactor AST loader may
     * trust strict hits without re-proving content.
     */
    public bool $clustersReplayed = false;

    /**
     * once-S70 design, surfaced at schema 1.8: the MASTER-side ML pair
     * client's connection status after ClusterStage ('off', 'idle',
     * 'reachable', 'unreachable' — see MlPairClient::connectionStatus()),
     * or null when no client was wired at all (no ml_pair_url configured,
     * or a cluster-cache replay that never scored a pair). Worker-side
     * dials inside the fork zone build their own clients and never cross
     * the scalar row wire back, so 'idle' on a parallel run honestly
     * means "the master's client never dialled", nothing more.
     */
    public ?string $mlConnectionStatus = null;

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
     * Epoch seconds (microtime(true)) stamped by Pipeline::iter() when a
     * run first starts pumping this state; null for a state no Pipeline
     * has ever driven. ReportStage turns (runStartedAt, build instant)
     * into the Report's ScanWindow — the once-S59 metadata source that
     * lets reporters emit real run timestamps without reading the clock
     * themselves.
     */
    public ?float $runStartedAt = null;

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
