<?php
declare(strict_types=1);

namespace Phpdup\Pipeline;

/**
 * Default no-op listener so stages don't have to null-check.
 */
final class NullProgressListener implements ProgressListener
{
    #[\Override]
    public function onStageStart(Stage $stage): void {}
    #[\Override]
    public function onStageEnd(Stage $stage): void {}
    #[\Override]
    public function onFileScanned(int $scanned, int $total): void {}
    #[\Override]
    public function onFilePreprocessed(int $processed, int $reused, int $errors): void {}
    #[\Override]
    public function onPairScored(int $scored, int $total): void {}
    #[\Override]
    public function onClusterRefactored(int $refactored, int $total): void {}
}
