<?php
declare(strict_types=1);

namespace Phpdup\Pipeline;

/**
 * Default no-op listener so stages don't have to null-check.
 */
final class NullProgressListener implements ProgressListener
{
    public function onStageStart(Stage $stage): void {}
    public function onStageEnd(Stage $stage): void {}
    public function onFileScanned(int $scanned, int $total): void {}
    public function onFilePreprocessed(int $processed, int $reused, int $errors): void {}
    public function onPairScored(int $scored, int $total): void {}
    public function onClusterRefactored(int $refactored, int $total): void {}
}
