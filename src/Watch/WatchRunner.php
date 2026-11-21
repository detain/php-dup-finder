<?php
declare(strict_types=1);

namespace Phpdup\Watch;

use Phpdup\Clustering\CandidateBudgetException;
use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Util\CountPhrase;
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
        $this->runGuarded($state);

        // Sync the FileWatcher with the initial file list so it can detect
        // new files added between runs.
        $fileInfos = $this->filesToSplFileInfo($state->files);
        $this->fileWatcher->syncFromFileList($fileInfos);

        $reloads = 0;
        $this->announceWatching(count($state->files));

        $loop = Loop::get();
        $running = true;
        $watcher = $this->fileWatcher;
        $rebuildState = $this->rebuildState;
        $output = $this->output;

        $watcher->watch(function (string $path, FileChangeType $type) use (&$reloads, $rebuildState, $output, $watcher): void {
            $reloads++;
            $output->writeln(sprintf(
                '<info>phpdup</info> change detected (%s: %s) — reload #%d',
                $type->value, $path, $reloads,
            ));
            $newState = $rebuildState();
            $this->runGuarded($newState);

            // Rebase the watcher snapshot on the new file list.
            $fileInfos = [];
            foreach ($newState->files as $f) {
                $fileInfos[] = new \SplFileInfo($f);
            }
            $watcher->syncFromFileList($fileInfos);

            $this->announceWatching(count($newState->files));
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
     * Run one pipeline pass with the candidate-budget guard rendered in the
     * same posture as the plain path's ft-n2 catch in Command::execute():
     * a budget halt is a runtime stop, not an invalid invocation, so it
     * prints the clean `phpdup:` error line instead of reaching Symfony's
     * throwable renderer with its usage-dump footer — and the watch loop
     * stays alive to pick up the next edit.
     */
    public function runGuarded(PipelineState $state): void
    {
        try {
            $this->pipeline->run($state, $this->output);
        } catch (CandidateBudgetException $e) {
            $this->output->writeln('<error>phpdup: ' . $e->getMessage() . '</error>');
        }
    }

    /**
     * Render the "watching N file(s) · last update …" surface. Grammar
     * routes through CountPhrase so a lone watched file reads "1 file".
     */
    public function announceWatching(int $fileCount): void
    {
        $this->output->writeln(sprintf(
            '<comment>watching %s · last update %s</comment>',
            CountPhrase::files($fileCount), date('H:i:s'),
        ));
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
