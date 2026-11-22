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
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Watch loop that re-runs the pipeline whenever tracked sources change.
 *
 * Uses FileWatcher for native file system event detection (inotify) with
 * polling fallback, so watch mode works across all platforms. The watcher
 * coalesces each burst of changes into ONE debounced callback (see
 * FileWatcher), which this runner turns into exactly one pipeline pass.
 * Each dispatch also pushes its state's Config::exclude view onto the
 * watcher before the run (once-S47), so the watch backends enumerate
 * under the same excludes the pipeline scanned under.
 *
 * Resilience (php-dup-finder-shell-023): a single poisoned pipeline pass
 * must never kill the long-lived loop. The pipeline call runs through
 * {@see runGuarded()}, which renders typed halts as the clean `phpdup:`
 * line and any other Throwable as a `watch pass failed` line, then keeps
 * going. A run of {@see MAX_CONSECUTIVE_PASS_FAILURES} crashing passes in
 * a row is a broken tree, not a transient: the runner then takes the
 * skip-note posture — further change events are acknowledged once with a
 * pause notice and no longer re-run the pipeline until the command is
 * restarted.
 *
 * Resilience closure (php-dup-finder-once-008): the catch-all covers the
 * ENTIRE dispatch, not just the pipeline call. State/pool construction
 * (the rebuildState closure — the finding's "misplaced config" vector),
 * the guarded pass and the watcher rebase form one seam
 * ({@see dispatchGuarded()}) used by BOTH the boot dispatch in run() and
 * every debounced event. A throw from any leg logs one clean
 * `watch pass failed` line and the daemon stays alive for the next
 * event; before this, construction and rebase throws escaped to
 * {@see \React\EventLoop\Loop::run()} and terminated `phpdup watch`.
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
     * @param FileWatcher|null $fileWatcher once-S47 seam: inject a watcher
     *                                                  (built on a fake loop) to pin
     *                                                  debounce/coalescing and the
     *                                                  exclude wiring end-to-end
     *                                                  through the real consumer;
     *                                                  null builds the production
     *                                                  auto-backend watcher.
     */
    public function __construct(
        private readonly Pipeline $pipeline,
        private readonly \Closure $rebuildState,
        private readonly OutputInterface $output,
        LoggerInterface $logger,
        string $scanRoot,
        float $intervalSeconds = 1.5,
        ?FileWatcher $fileWatcher = null,
    ) {
        $this->fileWatcher = $fileWatcher ?? new FileWatcher($logger, $scanRoot, $intervalSeconds);
    }

    public function run(): int
    {
        $this->output->writeln('<info>phpdup</info> watch mode (Ctrl+C to exit)');

        // once-S08 (php-dup-finder-once-008 closure): the boot dispatch is
        // the SAME guarded seam every event uses. Was: a throw from the
        // initial rebuildState (the finding's misplaced-config vector) or
        // the seeding resync propagated out of run() and Symfony's
        // throwable renderer killed `phpdup watch` before it ever entered
        // the loop. Now it logs one failure line and the loop starts
        // anyway — the next save gets a real pass.
        $this->announceWatching($this->dispatchGuarded());

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
     * One complete guarded dispatch (once-S08): fresh state/pool
     * construction, the pipeline pass through runGuarded(), and the
     * watcher rebase — every leg a poisoned tree can throw from — under a
     * single catch-all that logs via {@see notePassFailure()} and keeps
     * the caller's loop (or boot sequence) alive. Typed halts never reach
     * this catch: runGuarded swallows them as neutral. One seam, used by
     * boot AND every event, so the survival pins cover both paths.
     *
     * @return int file count for the announce surface (0 when the dispatch
     *             died before a state was built to count)
     */
    private function dispatchGuarded(): int
    {
        try {
            $state = ($this->rebuildState)();

            $this->runGuarded($state);

            // once-S47 (php-dup-finder-once-086): the watcher must walk the
            // same tree the pipeline does. The loop is single-threaded — a
            // flush can only ever run when a dispatch is NOT in flight —
            // so adopting the state's exclude view immediately before the
            // rebase puts the NEXT wake window on the same excludes the
            // pass scanned under. Until this leg existed the default view
            // never saw the globs, and vendor/.git-cache churn re-analyzed
            // every tick on the plain `phpdup watch` path. applyExcludes
            // short-circuits on an unchanged list; a changed one rebases
            // internally, the explicit resync() keeps the every-pass
            // fresh-key rebase (and the pinned rebase-throw position).
            $this->fileWatcher->applyExcludes($state->config->exclude);

            // Rebase the watcher on its OWN enumeration so the next flush
            // diffs against exactly what it would re-scan (an external
            // list shaped by the pipeline's exclude rules would
            // manufacture phantom Created events for every excluded-path
            // PHP file). A completed, gracefully halted OR crashed pass
            // all rebase the same way: the enumeration is the watcher's,
            // not the pass's.
            $this->fileWatcher->resync();

            return count($state->files);
        } catch (\Throwable $e) {
            $this->notePassFailure($e);
            return 0;
        }
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
        // shell-S34 (php-dup-finder-shell-025): $path arrives from the
        // watched tree — untrusted filesystem bytes. Markup-escape before
        // embedding it inside the writeln line (escape() is identity for
        // every innocuous path, so the shell-S33 render pins hold byte-exact).
        $this->output->writeln(sprintf(
            '<info>phpdup</info> change detected (%s: %s%s) — reload #%d',
            $type->value, self::escapeMarkup($path), $coalesced, $this->reloadCount,
        ));

        // once-S08 (php-dup-finder-once-008 closure): the WHOLE dispatch —
        // state construction, pipeline pass, watcher rebase, announce —
        // runs under the catch-all via dispatchGuarded(). shell-S33 armed
        // runGuarded() against pipeline Throwables, but the rebuildState
        // closure (this pass's fresh state/pool construction) and the
        // resync still escaped handleChange, rode out of Loop::run() and
        // terminated the daemon: the finding's "misplaced config" vector
        // survived its own fix. Anything that throws now logs, counts
        // toward the skip-note streak, and leaves the loop alive for the
        // next event to re-analyze.
        $this->announceWatching($this->dispatchGuarded());
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
     * once-S08: this seam guards ONLY the pipeline call (public tests
     * drive it directly). The state-construction and watcher-rebase legs
     * of a dispatch are guarded by the catch-all in handleChange()/run(),
     * which funnels through the same {@see notePassFailure()} arm, so
     * every way a pass can die shares one render and one streak counter.
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
            // shell-S34: a ScriptKindApplyRefusal::forBlock message embeds
            // the offending block's file path — raw filesystem bytes.
            $this->output->writeln('<error>phpdup: ' . self::escapeMarkup($e->getMessage()) . '</error>');
            return true;
        } catch (\Throwable $e) {
            $this->notePassFailure($e);
            return false;
        }
    }

    /**
     * The one crash posture (once-S08): counts the pass toward the
     * consecutive-failure cap and renders a single clean `phpdup:` line
     * naming the Throwable class and message. Routed to from the
     * pipeline arm of runGuarded() AND the whole-dispatch catch-alls in
     * handleChange()/run(), so a construction throw, a rebase throw and
     * a mid-stage crash are all logged, counted and survived the same
     * way — never a Symfony usage dump, never process death.
     */
    private function notePassFailure(\Throwable $e): void
    {
        $this->consecutivePassFailures++;
        // shell-S34: $e::class is PHP-internal (trusted); the message often
        // embeds filesystem bytes from whatever leg crashed — escape them.
        $this->output->writeln(sprintf(
            '<error>phpdup: watch pass failed (%s): %s</error>',
            $e::class,
            self::escapeMarkup($e->getMessage()),
        ));
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
     * shell-S34 (php-dup-finder-shell-025): $bytes as literal console text.
     * Symfony's formatter consumes `<...>` inside any writeln() line, so
     * raw path/message bytes embedded mid-line are markup — and injection —
     * material (SEC proved `<fg=red>` swallowing). OutputFormatter::escape()
     * carries Symfony's round-trip guarantee: the formatter prints the
     * result byte-for-byte as the original value, and innocuous values pass
     * through unchanged, so the shell-S33 render pins stay byte-exact.
     */
    private static function escapeMarkup(string $bytes): string
    {
        return OutputFormatter::escape($bytes);
    }
}
