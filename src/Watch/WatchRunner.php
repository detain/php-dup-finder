<?php
declare(strict_types=1);

namespace Phpdup\Watch;

use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use React\EventLoop\Loop;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Polling watch loop: re-runs the pipeline whenever any tracked source file's
 * mtime changes. Polling (rather than inotify/FSEvents) keeps the watcher
 * dependency-free and portable, at the cost of a small (default 1.5s) latency.
 *
 * Ctrl+C / SIGINT triggers a graceful exit: the loop tears down and the parent
 * Command returns 0.
 */
final class WatchRunner
{
    /**
     * @param \Closure(): PipelineState $rebuildState  Constructs a fresh PipelineState
     *                                                  for each iteration; called once
     *                                                  per re-run (and once at startup).
     */
    public function __construct(
        private readonly Pipeline $pipeline,
        private readonly \Closure $rebuildState,
        private readonly OutputInterface $output,
        private readonly float $intervalSeconds = 1.5,
    ) {}

    public function run(): int
    {
        $this->output->writeln('<info>phpdup</info> watch mode (Ctrl+C to exit)');

        $state = ($this->rebuildState)();
        $this->pipeline->run($state, $this->output);
        $reloads = 0;
        $mtimes  = $this->snapshotMtimes($state->files);
        $this->output->writeln(sprintf(
            '<comment>watching %d files · last update %s</comment>',
            count($mtimes), date('H:i:s'),
        ));

        $loop = Loop::get();
        $running = true;

        $loop->addPeriodicTimer($this->intervalSeconds, function () use (&$mtimes, &$reloads) {
            $changed = $this->pollChanges($mtimes);
            if ($changed === []) {
                return;
            }
            $reloads++;
            $this->output->writeln(sprintf(
                '<info>phpdup</info> change detected (%d files) — reload #%d',
                count($changed), $reloads,
            ));
            $newState = ($this->rebuildState)();
            $this->pipeline->run($newState, $this->output);
            $mtimes = $this->snapshotMtimes($newState->files);
            $this->output->writeln(sprintf(
                '<comment>watching %d files · last update %s</comment>',
                count($mtimes), date('H:i:s'),
            ));
        });

        if (function_exists('pcntl_signal')) {
            $stop = function () use ($loop, &$running) {
                if (!$running) return;
                $running = false;
                $this->output->writeln("\n<info>phpdup</info> watch mode stopping");
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
     * @return array<string,int>
     */
    private function snapshotMtimes(array $files): array
    {
        $out = [];
        foreach ($files as $f) {
            $m = @filemtime($f);
            if ($m !== false) {
                $out[$f] = $m;
            }
        }
        return $out;
    }

    /**
     * @param array<string,int> $previous  current snapshot, mutated to reflect new mtimes
     * @return list<string> files whose mtime changed (or that disappeared/appeared)
     */
    private function pollChanges(array &$previous): array
    {
        $changed = [];
        foreach ($previous as $f => $oldMtime) {
            clearstatcache(true, $f);
            $m = @filemtime($f);
            if ($m === false) {
                $changed[] = $f;
                unset($previous[$f]);
                continue;
            }
            if ($m !== $oldMtime) {
                $changed[] = $f;
                $previous[$f] = $m;
            }
        }
        return $changed;
    }
}
