<?php
declare(strict_types=1);

namespace Phpdup\Tui;

use Phpdup\Cli\SignalHandler;
use Phpdup\Pipeline\PipelineState;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Cli\Theme;

/**
 * Boots a SugarCraft {@see Program} for a {@see PhpdupModel}.
 *
 * Two modes:
 *   - "post-hoc" — pipeline ran first, model holds the final state, TUI is just an
 *     interactive viewer. {@see run()} / {@see runWithModel()}.
 *   - "live" — model receives an iterator factory and drives the cooperative
 *     pipeline from inside the runtime via StagePumpedMsg. {@see buildLiveModel()}.
 */
final class TuiRunner
{
    public function run(PipelineState $state, string $themeName, bool $useAltScreen = true): int
    {
        $view = new ViewState();
        $view->analysisComplete = true;
        $model = new PhpdupModel($state, $view, self::resolveTheme($themeName));
        return $this->runWithModel($model, $useAltScreen);
    }

    /**
     * Boot the program with a pre-built model. Used both for post-hoc viewing of a
     * completed pipeline and for live mode where the model carries its own
     * iterator factory.
     *
     * once-S07 (php-dup-finder-once-007): the return value is the model's
     * {@see PhpdupModel::exitCode()} — a pipeline halt that the TUI surfaced
     * as an error panel exits 1, the same runtime-error posture the CLI
     * uses, instead of the previous unconditional 0 that made a crash look
     * like a clean run to scripts and CI.
     *
     * once-S22 (php-dup-finder-once-025) boot contract: this method (and
     * {@see makeProgram()}) is reached from the CLI only AFTER Command's
     * admission doors have closed — an invalid --theme exits 2 at
     * parseRuntimeOptions before dispatch ever builds a model, and --tui on
     * a non-TTY STDOUT is likewise refused there (exit 2, hint toward the
     * default CLI) so alt-screen bytes can never be painted into a pipe.
     * A new boot site that bypasses Command (once-S75 and the S07→S22→S75
     * spine) must replicate that admission first — the runner itself stays
     * stream-agnostic on purpose so the in-process Program pins keep
     * driving it through socketpair/memory streams.
     *
     * @param mixed $input  readable stream handed to the runtime, null = STDIN
     * @param mixed $output writable stream handed to the runtime, null = STDOUT
     */
    public function runWithModel(
        PhpdupModel $model,
        bool $useAltScreen = true,
        ?LoopInterface $loop = null,
        mixed $input = null,
        mixed $output = null,
    ): int {
        $reactLoop = $loop ?? Loop::get();
        $options   = new ProgramOptions(
            useAltScreen: $useAltScreen,
            catchInterrupts: true,
            hideCursor: true,
            input: $input,
            output: $output,
            loop: $reactLoop,
        );

        // once-S07: the return value is the model's exitCode() — this line
        // must stay on the other side of the signal scope for that posture
        // to hold, so the scope lives in the helper, not around the call.
        $this->runProgramScoped(new Program($model, $options), $model, $reactLoop);

        return $model->exitCode();
    }

    /**
     * Build a {@see Program} bound to a pre-built model without running it.
     * Used by watch-mode where the caller wants to keep a reference to send
     * messages while the loop runs.
     */
    public function makeProgram(PhpdupModel $model, bool $useAltScreen = true, ?LoopInterface $loop = null): Program
    {
        return new Program($model, new ProgramOptions(
            useAltScreen: $useAltScreen,
            catchInterrupts: true,
            hideCursor: true,
            loop: $loop,
        ));
    }

    /**
     * Drive a watch-mode Program (built by {@see makeProgram()} and already
     * wired to its file watcher by the caller) under the once-S75 scoped
     * SIGINT contract, then report the watch-TUI's historical graceful-stop
     * status. Returns 0 exactly as the old straight `$program->run();
     * return 0;` tail did — the watch-TUI belt posture is unchanged.
     */
    public function runWatchProgram(Program $program, PhpdupModel $model, LoopInterface $loop): int
    {
        $this->runProgramScoped($program, $model, $loop);
        return 0;
    }

    /**
     * once-S75 (php-dup-finder-once-090): run a Program under a scoped
     * SIGINT contract that replaces — from the first loop tick — the
     * cooperative arm sugar-core's Program installs inside run().
     *
     * Too early is impossible (run() arms its own handler synchronously on
     * entry), so the timer lands on the first tick, microseconds after.
     * From then on every press hits our scope: the first marks the model's
     * state cancelled (the pool/stages unwind promptly — the delayed
     * Ctrl+C this finding measured) and hard-stops the program through its
     * public kill(), the same synchronous stopProgram body Program's own
     * SIGINT listener ran; the second reaps children and exits 130 now.
     *
     * Because our scope is armed INSIDE run() but restored outside it, the
     * layers unwind non-LIFO — Program's teardown only knows the handler it
     * captured at its entry. The entry snapshot therefore gets the last
     * word: whatever the process walked in with is exactly what it walks
     * out with (async mode included — the S93 "leave it as found"
     * discipline).
     */
    private function runProgramScoped(Program $program, PhpdupModel $model, LoopInterface $loop): void
    {
        if (!function_exists('pcntl_signal')) {
            $program->run();
            return;
        }

        $entry          = SignalHandler::captureEnvironment();
        $restoreSignals = null;
        $timer          = $loop->addTimer(0.001, static function () use (&$restoreSignals, $model, $program): void {
            $restoreSignals = SignalHandler::install(static function () use ($model, $program): void {
                $model->state->cancelled = true;
                $program->kill();
            });
        });

        try {
            $program->run();
        } finally {
            // Never let an un-fired one-shot leak into the next loop run
            // (the loop is a singleton; sibling tests reuse it).
            $loop->cancelTimer($timer);
            if ($restoreSignals !== null) {
                $restoreSignals();
            }
            $entry();
        }
    }

    public function buildModel(PipelineState $state, string $themeName): PhpdupModel
    {
        return new PhpdupModel($state, new ViewState(), self::resolveTheme($themeName));
    }

    /**
     * Build a model that drives the cooperative pipeline from inside the runtime.
     *
     * @param \Closure(): array{0: \Generator, 1: PipelineState} $iteratorFactory
     */
    public function buildLiveModel(string $themeName, \Closure $iteratorFactory): PhpdupModel
    {
        $initialRun = $iteratorFactory();
        return new PhpdupModel(
            $initialRun[1],
            new ViewState(),
            self::resolveTheme($themeName),
            $iteratorFactory,
            null,
            $initialRun,
        );
    }

    /**
     * Map a theme name to a Theme preset. The ansi fallback on an unknown
     * name is the deliberate API valve (pinned by
     * TuiRunnerTest::testResolveThemeFallsBackToAnsiForUnknownName) for
     * programmatic callers. It is UNREACHABLE from the CLI: a bogus --theme
     * is refused with exit 2 at Command's admission door before any model or
     * Program is built (shell-S24 door, verified render-free by once-S22 /
     * php-dup-finder-once-025), so "bogus theme keeps rendering" cannot
     * happen on any command path.
     */
    public static function resolveTheme(string $name): Theme
    {
        return match (strtolower($name)) {
            'plain'      => Theme::plain(),
            'charm'      => Theme::charm(),
            'dracula'    => Theme::dracula(),
            'nord'       => Theme::nord(),
            'catppuccin' => Theme::catppuccin(),
            default      => Theme::ansi(),
        };
    }

    /** @return list<string> */
    public static function knownThemes(): array
    {
        return ['ansi', 'plain', 'charm', 'dracula', 'nord', 'catppuccin'];
    }
}
