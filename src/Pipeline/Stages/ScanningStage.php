<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\StageInterface;
use Phpdup\Scanning\FileScanner;
use Symfony\Component\Console\Output\OutputInterface;

final class ScanningStage implements StageInterface
{
    public function name(): Stage
    {
        return Stage::Scanning;
    }

    public function run(PipelineState $state, OutputInterface $output): void
    {
        $config  = $state->config;
        $scanner = new FileScanner($config->exclude);

        $files = [];
        foreach ($config->paths as $root) {
            foreach ($scanner->scan($root) as $path) {
                $files[] = $path;
                $state->scannedFiles = ++$state->totalFiles;
            }
        }
        sort($files);

        $state->files = $files;
        $state->totalFiles = count($files);
        $state->scannedFiles = count($files);

        $output->writeln(sprintf(
            "<info>phpdup</info> scanning %d path(s) → %d files",
            count($config->paths), count($files)
        ));
    }
}
