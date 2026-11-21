<?php
declare(strict_types=1);

namespace Phpdup\Watch;

use Phpdup\Clustering\CandidateBudgetException;
use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Refactor\ScriptKindApplyRefusal;
use Phpdup\Util\CountPhrase;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Watch loop that re-runs the pipeline whenever tracked sources change.
 *
 * Uses FileWatcher for native file system event detection (inotify) with
 * polling fallback, so watch mode works across all platforms. The watcher
 * coalesces each burst of changes into ONE debounced callback (see
 * FileWatcher), which this runner turns into exactly one pipeline pass.
 *
 * Resilience (php-dup-finder-shell-023): a single poisoned pipeline pass
 * must never kill the long-lived loop. Every pass runs through
 * {@see runGuarded()}, which renders typed halts as the clean `phpdup:`
 * line and any other Throwable as a `watch pass failed` line, then keeps
 * going. A run of {@see MAX_CONSECUTIVE_PASS_FAILURES} crashing passes in
 * a row is a broken tree, not a transient: the runner then takes the
 * skip-note posture — further change events are acknowledged once with a
 * pause notice and no longer re-run the pipeline until the command is
 * restarted.
 *
 * Ctrl+C / SIGINT triggers a graceful exit: the loop tears down and the
 * parent Command returns 0.
 */
final class WatchRunner
{
    /**
     * Consecutive crashing passes tolerated before the watch loop stops
     * re-running on file changes (skip-note posture, pinned by tests).
     * Typed halts (budget, script-kind refusal) are neutral: they neither
     * extend nor reset the streak — they are expected user-input states,
     * not symptoms of a broken tree.
     */
    public const MAX_CONSECUTIVE_PASS_FAILURES = 3;

    private FileWatcher $fileWatcher;

    private int $reloadCount = 0;

    private int $consecutivePassFailures = 0;

    private bool $pauseNoted = false;

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

        // Seed the watcher snapshot from its OWN enumeration so the first
        // flush diffs against exactly what it would re-scan (an external
        // list shaped by the pipeline's exclude rules would manufacture
        // phantom Created events for every excluded-path PHP file).
        $this->fileWatcher->resync();

        $this->announceWatching(count($state->files));

        $loop = Loop::get();
        $running = true;
        $watcher = $this->fileWatcher;

        $watcher->watch([$this, 'handleChange']);

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
     * Consumer seam for FileWatcher::watch(): one call per debounced
     * flush = one pipeline pass. Public so tests can drive the exact
     * callback the live loop installs, without entering the blocking
     * event loop (the convention the announce/budget pins already use).
     *
     * @param array<string,FileChangeType> $batch the coalesced changes of this window
     */
    public function handleChange(string $path, FileChangeType $type, array $batch = []): void
    {
        if ($this->consecutivePassFailures >= self::MAX_CONSECUTIVE_PASS_FAILURES) {
            // Skip-note posture (pinned): after the cap, events no longer
            // re-run the pipeline; the notice is rendered exactly once.
            if (!$this->pauseNoted) {
                $this->pauseNoted = true;
                $this->output->writeln(
                    '<comment>phpdup: watch mode paused after ' . self::MAX_CONSECUTIVE_PASS_FAILURES
                    . ' consecutive failed passes — fix the reported error and restart the command to resume watching</comment>'
                );
            }
            return;
        }

        $this->reloadCount++;
        $coalesced = count($batch) > 1 ? sprintf(' · %d files coalesced', count($batch)) : '';
        $this->output->writeln(sprintf(
            '<info>phpdup</info> change detected (%s: %s%s) — reload #%d',
            $type->value, $path, $coalesced, $this->reloadCount,
        ));

        $newState = ($this->rebuildState)();
        $this->runGuarded($newState);

        // Rebase the watcher on its own enumeration: a completed (or
        // gracefully halted) pass may have discovered or dropped files,
        // and identical enumeration on both sides keeps the next flush
        // honest.
        $this->fileWatcher->resync();

        $this->announceWatching(count($newState->files));
    }

    /**
     * Run one pipeline pass defensively (php-dup-finder-shell-023).
     *
     * Three postures, all keeping the loop alive:
     *  - CandidateBudgetException / ScriptKindApplyRefusal: typed runtime
     *    halts, rendered as the clean `phpdup:` line in the same posture
     *    as the plain path's ft-n2 catch in Command::execute(). Neutral
     *    for the failure streak.
     *  - any other Throwable (poisoned cache row, parse fatal, a stage
     *    crash): rendered as a watch-pass failure — NOT via Symfony's
     *    throwable renderer, whose usage dump would have to end the
     *    process — and counted.
     *  - clean completion: resets the consecutive-failure streak.
     *
     * @return bool true when the pass did NOT crash (a graceful typed halt
     *              counts as not-crashed but see the streak rules above)
     */
    public function runGuarded(PipelineState $state): bool
    {
        try {
            $this->pipeline->run($state, $this->output);
            $this->consecutivePassFailures = 0;
            return true;
        } catch (CandidateBudgetException | ScriptKindApplyRefusal $e) {
            $this->output->writeln('<error>phpdup: ' . $e->getMessage() . '</error>');
            return true;
        } catch (\Throwable $e) {
            $this->consecutivePassFailures++;
            $this->output->writeln(sprintf(
                '<error>phpdup: watch pass failed (%s): %s</error>',
                $e::class,
                $e->getMessage(),
            ));
            return false;
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
}
