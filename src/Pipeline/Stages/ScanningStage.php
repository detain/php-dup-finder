<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Pipeline\CooperativeStageInterface;
use Phpdup\Pipeline\NullProgressListener;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\ProgressListener;
use Phpdup\Pipeline\Stage;
use Phpdup\Scanning\FileScanner;
use Phpdup\Util\MemoryDebug;
use Symfony\Component\Console\Output\OutputInterface;

final class ScanningStage implements CooperativeStageInterface
{
    /** Yield to the runtime after every N files so the TUI can repaint. */
    private const YIELD_INTERVAL = 16;

    private readonly ProgressListener $listener;

    public function __construct(?ProgressListener $listener = null)
    {
        $this->listener = $listener ?? new NullProgressListener();
    }

    public function name(): Stage
    {
        return Stage::Scanning;
    }

    public function run(PipelineState $state, OutputInterface $output): void
    {
        foreach ($this->iter($state, $output) as $_) {
            // synchronous drain
        }
    }

    public function iter(PipelineState $state, OutputInterface $output): \Generator
    {
        $config  = $state->config;
        $scanner = new FileScanner($config->exclude);

        $files = [];
        $sinceYield = 0;
        $state->stageStartTime = microtime(true);
        foreach ($config->paths as $root) {
            foreach ($scanner->scan($root) as $path) {
                $files[] = $path;
                $state->totalFiles++;
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG && $state->totalFiles % 100 === 0) {
                    $msg = sprintf('scanning: discovered file %s [%s]', $path, MemoryDebug::getMemoryUsage());
                    $output->writeln($msg);
                    $state->pushDebugMessage($msg);
                }
                if (++$sinceYield >= self::YIELD_INTERVAL) {
                    $sinceYield = 0;
                    $state->sampleMemory();
                    $state->debug($output, sprintf('scanning: %d files scanned so far [%s]', $state->totalFiles, MemoryDebug::getMemoryUsage()));
                    // Update scannedFiles only at yield intervals so the TUI
                    // sees real progress (not 100 % after every single file).
                    $state->scannedFiles = $state->totalFiles;
                    $this->listener->onFileScanned($state->scannedFiles, $state->totalFiles);
                    yield Stage::Scanning;
                }
            }
        }
        sort($files);

        $state->files = $files;
        $state->totalFiles = count($files);
        $state->scannedFiles = count($files);

        // Cover any remaining files that didn't fill a full yield-interval
        // batch (the listener was only called at yield boundaries above).
        $this->listener->onFileScanned($state->scannedFiles, $state->totalFiles);

        $output->writeln(sprintf(
            "<info>phpdup</info> scanning %d path(s) → %d files",
            count($config->paths), count($files)
        ));
    }
}
