<?php
declare(strict_types=1);

namespace Phpdup\Watch;

use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Watch loop that re-runs the pipeline whenever any tracked source file changes.
 *
 * Uses FileWatcher for native file system event detection (inotify/FSEvents) with
 * polling fallback. This ensures watch mode works across all platforms.
 *
 * Ctrl+C / SIGINT triggers a graceful exit: the loop tears down and the parent
 * Command returns 0.
 */
final class WatchRunner
{
    private FileWatcher $fileWatcher;

    /**
     * @param \Closure(): PipelineState $rebuildState  Constructs a fresh PipelineState
     *                                                  for each iteration; called once
     *                                                  per re-run (and once at startup).
     */
    public function __construct(
        private readonly Pipeline $pipeline,
        private readonly \Closure $rebuildState,
        private readonly OutputInterface $output,
        LoggerInterface $logger,
        string $scanRoot,
        float $intervalSeconds = 1.5,
    ) {
        $this->fileWatcher = new FileWatcher($logger, $scanRoot, $intervalSeconds);
    }

    public function run(): int
    {
        $this->output->writeln('<info>phpdup</info> watch mode (Ctrl+C to exit)');

        $state = ($this->rebuildState)();
        $this->pipeline->run($state, $this->output);

        // Sync the FileWatcher with the initial file list so it can detect
        // new files added between runs.
        $fileInfos = $this->filesToSplFileInfo($state->files);
        $this->fileWatcher->syncFromFileList($fileInfos);

        $reloads = 0;
        $this->output->writeln(sprintf(
            '<comment>watching %d files · last update %s</comment>',
            count($state->files), date('H:i:s'),
        ));

        $loop = Loop::get();
        $running = true;
        $watcher = $this->fileWatcher;
        $rebuildState = $this->rebuildState;
        $pipeline = $this->pipeline;
        $output = $this->output;

        $watcher->watch(function (string $path, FileChangeType $type) use (&$reloads, $rebuildState, $pipeline, $output, $watcher): void {
            $reloads++;
            $output->writeln(sprintf(
                '<info>phpdup</info> change detected (%s: %s) — reload #%d',
                $type->value, $path, $reloads,
            ));
            $newState = $rebuildState();
            $pipeline->run($newState, $output);

            // Rebase the watcher snapshot on the new file list.
            $fileInfos = [];
            foreach ($newState->files as $f) {
                $fileInfos[] = new \SplFileInfo($f);
            }
            $watcher->syncFromFileList($fileInfos);

            $output->writeln(sprintf(
                '<comment>watching %d files · last update %s</comment>',
                count($newState->files), date('H:i:s'),
            ));
        });

        if (function_exists('pcntl_signal')) {
            $stop = function () use ($loop, &$running): void {
                if (!$running) return;
                $running = false;
                $this->output->writeln("\n<info>phpdup</info> watch mode stopping");
                $this->fileWatcher->stop();
                $loop->stop();
            };
            $loop->addSignal(SIGINT, $stop);
            $loop->addSignal(SIGTERM, $stop);
        }

        $loop->run();
        return 0;
    }

    /**
     * @param list<string> $files
     * @return list<\SplFileInfo>
     */
    private function filesToSplFileInfo(array $files): array
    {
        $infos = [];
        foreach ($files as $f) {
            $infos[] = new \SplFileInfo($f);
        }
        return $infos;
    }
}
