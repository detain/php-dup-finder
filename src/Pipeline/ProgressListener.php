<?php
declare(strict_types=1);

namespace Phpdup\Pipeline;

/**
 * Optional observer pipeline stages call to report progress.
 *
 * Implementations are passed into stage constructors. Stages MUST tolerate
 * a {@see NullProgressListener} (or any listener that ignores events) — they
 * never branch behaviour on whether anyone's listening.
 *
 * The TUI listens to mutate {@see PipelineState} fields the dashboard reads
 * (totalFiles, scannedFiles, etc.); CI mode passes a NullProgressListener.
 */
interface ProgressListener
{
    public function onStageStart(Stage $stage): void;

    public function onStageEnd(Stage $stage): void;

    /** Per-file scan tick. */
    public function onFileScanned(int $scanned, int $total): void;

    /** Per-file preprocess result. */
    public function onFilePreprocessed(int $processed, int $reused, int $errors): void;

    /** Periodic clustering tick — scored / total candidate pairs so far. */
    public function onPairScored(int $scored, int $total): void;

    /** A cluster was finalised (emitted after refactoring synthesis). */
    public function onClusterRefactored(int $refactored, int $total): void;
}
